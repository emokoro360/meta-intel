"""
MetaIntel - Metadata Extraction Microservice
FastAPI + ExifTool + Pillow + imagehash
"""
from __future__ import annotations

import asyncio
import hashlib
import json
import logging
import os
import subprocess
import tempfile
from pathlib import Path
from typing import Any, Optional
from urllib.request import urlretrieve

import aiohttp
import imagehash
import uvicorn
from fastapi import FastAPI, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from PIL import Image, ExifTags, TiffImagePlugin
from pydantic import BaseModel, HttpUrl
import struct

# ─── Logging ─────────────────────────────────────────────────────────────────
logging.basicConfig(level=os.getenv("LOG_LEVEL", "INFO"))
logger = logging.getLogger("metadata-service")

app = FastAPI(
    title="MetaIntel Metadata Service",
    version="1.0.0",
    description="High-performance image metadata extraction microservice",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

EXIFTOOL_PATH = os.getenv("EXIFTOOL_PATH", "exiftool")
WORKERS       = int(os.getenv("WORKERS", 4))
TEMP_DIR      = Path(tempfile.gettempdir()) / "metaintel"
TEMP_DIR.mkdir(exist_ok=True)


# ─── Request / Response Models ────────────────────────────────────────────────
class ExtractionRequest(BaseModel):
    image_path: str          # local path or HTTP URL
    image_id:   str
    mime_type:  Optional[str] = None
    options:    dict         = {}

class PHashRequest(BaseModel):
    image_path: str
    image_id:   str

class SanitizeRequest(BaseModel):
    image_path:   str
    image_id:     str
    output_path:  Optional[str] = None
    keep_fields:  list[str]     = []   # fields to keep (e.g. ['ColorSpace', 'Orientation'])


# ─── ExifTool Wrapper ─────────────────────────────────────────────────────────
class ExifToolWrapper:
    """
    Async ExifTool wrapper that supports batch processing with the
    ExifTool -stay_open persistent process mode for maximum performance.
    """

    def __init__(self):
        self._process: Optional[asyncio.subprocess.Process] = None

    async def _get_process(self) -> asyncio.subprocess.Process:
        if self._process is None or self._process.returncode is not None:
            self._process = await asyncio.create_subprocess_exec(
                EXIFTOOL_PATH,
                "-stay_open", "True",
                "-@", "-",
                stdin=asyncio.subprocess.PIPE,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.DEVNULL,
            )
            logger.info("ExifTool persistent process started")
        return self._process

    async def extract_all(self, file_path: str, options: dict) -> dict:
        """Extract all metadata groups from a file."""
        cmd_args = [
            "-json",
            "-struct",
            "-all:all",
            "-g",            # group output by tag category
            "-n",            # numeric values (no conversion)
            "-charset", "UTF8",
        ]

        if not options.get("binary_data", False):
            cmd_args.append("-b")

        cmd_args.append(file_path)

        result = await self._run_exiftool(cmd_args)
        if result and len(result) > 0:
            return result[0]
        return {}

    async def _run_exiftool(self, args: list[str]) -> list[dict]:
        """Run exiftool as a one-shot subprocess (simpler, more reliable)."""
        try:
            cmd = [EXIFTOOL_PATH] + args
            proc = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
            )
            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=30)

            if proc.returncode not in (0, 1):  # ExifTool returns 1 on minor warnings
                logger.warning(f"ExifTool stderr: {stderr.decode()}")

            return json.loads(stdout.decode("utf-8", errors="replace")) or []

        except asyncio.TimeoutError:
            logger.error("ExifTool timed out")
            return []
        except json.JSONDecodeError as e:
            logger.error(f"ExifTool JSON decode error: {e}")
            return []

    async def sanitize(self, input_path: str, output_path: str, keep_fields: list[str]) -> bool:
        """Strip all metadata, optionally keeping specified fields."""
        args = ["-all=", "-overwrite_original"]

        # Re-add fields to keep
        for field in keep_fields:
            args.extend([f"-{field}<{field}"])

        args.extend(["-o", output_path, input_path])

        try:
            proc = await asyncio.create_subprocess_exec(
                EXIFTOOL_PATH, *args,
                stdout=asyncio.subprocess.DEVNULL,
                stderr=asyncio.subprocess.DEVNULL,
            )
            await asyncio.wait_for(proc.wait(), timeout=30)
            return proc.returncode == 0
        except Exception as e:
            logger.error(f"Sanitize failed: {e}")
            return False


exiftool = ExifToolWrapper()


# ─── Image Acquisition ────────────────────────────────────────────────────────
async def acquire_image(image_path: str) -> Path:
    """Download if URL, otherwise use local path."""
    if image_path.startswith(("http://", "https://")):
        tmp_path = TEMP_DIR / f"dl_{hashlib.md5(image_path.encode()).hexdigest()}.tmp"
        if not tmp_path.exists():
            async with aiohttp.ClientSession() as session:
                async with session.get(image_path, timeout=aiohttp.ClientTimeout(total=30)) as resp:
                    resp.raise_for_status()
                    with open(tmp_path, "wb") as f:
                        async for chunk in resp.content.iter_chunked(1024 * 64):
                            f.write(chunk)
        return tmp_path
    else:
        p = Path(image_path)
        if not p.exists():
            raise HTTPException(404, f"File not found: {image_path}")
        return p


# ─── Metadata Enhancement ─────────────────────────────────────────────────────
def enhance_metadata(raw: dict, file_path: Path) -> dict:
    """Add computed/enhanced fields not available directly from ExifTool."""
    enhanced = dict(raw)

    # File hash verification
    sha256 = hashlib.sha256()
    md5    = hashlib.md5()
    with open(file_path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            sha256.update(chunk)
            md5.update(chunk)

    enhanced.setdefault("FileHashes", {})
    enhanced["FileHashes"]["SHA256"] = sha256.hexdigest()
    enhanced["FileHashes"]["MD5"]    = md5.hexdigest()

    # Pillow-level info (catches some things ExifTool misses)
    try:
        with Image.open(file_path) as img:
            enhanced.setdefault("Computed", {})
            enhanced["Computed"]["ActualWidth"]  = img.width
            enhanced["Computed"]["ActualHeight"] = img.height
            enhanced["Computed"]["Mode"]         = img.mode
            enhanced["Computed"]["Format"]       = img.format
            enhanced["Computed"]["IsAnimated"]   = hasattr(img, "n_frames") and img.n_frames > 1

            # ICC profile detection
            if "icc_profile" in img.info:
                enhanced["Computed"]["HasICCProfile"] = True
                enhanced["Computed"]["ICCProfileSize"] = len(img.info["icc_profile"])
            else:
                enhanced["Computed"]["HasICCProfile"] = False

            # Thumbnail detection
            if "thumbnail" in img.info:
                enhanced["Computed"]["HasThumbnail"] = True

            # JFIF / APP0 marker info
            if hasattr(img, "applist"):
                markers = [app[0] for app in img.applist]
                enhanced["Computed"]["JPEGMarkers"] = markers

    except Exception as e:
        logger.debug(f"Pillow enhancement partial failure: {e}")

    return enhanced


# ─── Software Fingerprinting ──────────────────────────────────────────────────
KNOWN_EDITING_SOFTWARE = {
    "adobe photoshop":    {"category": "professional_editing", "risk": "medium"},
    "adobe lightroom":    {"category": "professional_editing", "risk": "low"},
    "gimp":               {"category": "open_source_editing",  "risk": "medium"},
    "snapseed":           {"category": "mobile_editing",       "risk": "low"},
    "instagram":          {"category": "social_media",         "risk": "low"},
    "facetune":           {"category": "portrait_manipulation","risk": "high"},
    "meitu":              {"category": "portrait_manipulation","risk": "high"},
    "retouchme":          {"category": "portrait_manipulation","risk": "high"},
    "adobe premiere":     {"category": "video_editor",         "risk": "medium"},
    "final cut pro":      {"category": "video_editor",         "risk": "medium"},
    "canva":              {"category": "design_tool",          "risk": "low"},
    "midjourney":         {"category": "ai_generated",         "risk": "critical"},
    "stable diffusion":   {"category": "ai_generated",         "risk": "critical"},
    "dall-e":             {"category": "ai_generated",         "risk": "critical"},
    "firefly":            {"category": "ai_generated",         "risk": "critical"},
}

def fingerprint_software(metadata: dict) -> dict:
    """Identify editing software from metadata fields."""
    software_fields = [
        metadata.get("EXIF", {}).get("Software", ""),
        metadata.get("XMP", {}).get("CreatorTool", ""),
        metadata.get("XMP", {}).get("HistorySoftwareAgent", ""),
        metadata.get("Photoshop", {}).get("ApplicationRecordVersion", ""),
    ]

    detections = []
    for field_val in software_fields:
        if not field_val:
            continue
        field_lower = str(field_val).lower()
        for sw_key, sw_info in KNOWN_EDITING_SOFTWARE.items():
            if sw_key in field_lower:
                detections.append({
                    "software":  field_val,
                    "category":  sw_info["category"],
                    "risk_level":sw_info["risk"],
                    "source":    "metadata_field",
                })

    # Check XMP history for edit chain
    xmp_history = metadata.get("XMP", {}).get("History", [])
    if isinstance(xmp_history, list):
        for entry in xmp_history:
            agent = str(entry.get("SoftwareAgent", "")).lower()
            for sw_key, sw_info in KNOWN_EDITING_SOFTWARE.items():
                if sw_key in agent:
                    detections.append({
                        "software":  entry.get("SoftwareAgent"),
                        "category":  sw_info["category"],
                        "risk_level":sw_info["risk"],
                        "source":    "xmp_history",
                        "timestamp": entry.get("When"),
                    })

    return {
        "detections":     detections,
        "has_edits":      len(detections) > 0,
        "highest_risk":   max((d["risk_level"] for d in detections),
                              key=lambda r: ["low","medium","high","critical"].index(r),
                              default="none") if detections else "none",
    }


# ─── API Endpoints ────────────────────────────────────────────────────────────
@app.get("/health")
async def health():
    # Verify ExifTool is available
    try:
        proc = await asyncio.create_subprocess_exec(
            EXIFTOOL_PATH, "-ver",
            stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL
        )
        stdout, _ = await proc.communicate()
        version   = stdout.decode().strip()
    except Exception:
        version = "unavailable"

    return {"status": "ok", "exiftool_version": version, "service": "metadata-service"}


@app.post("/extract")
async def extract_metadata(req: ExtractionRequest):
    """Extract all metadata from an image."""
    try:
        file_path = await acquire_image(req.image_path)
        raw       = await exiftool.extract_all(str(file_path), req.options)
        enhanced  = enhance_metadata(raw, file_path)
        sw_fp     = fingerprint_software(enhanced)

        # Clean temp file if downloaded
        if str(file_path).startswith(str(TEMP_DIR)):
            try: file_path.unlink()
            except: pass

        return {
            "status":              "success",
            "image_id":            req.image_id,
            "data":                enhanced,
            "software_fingerprint":sw_fp,
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Extraction failed for {req.image_id}: {e}", exc_info=True)
        raise HTTPException(500, f"Extraction failed: {str(e)}")


@app.post("/phash")
async def compute_phash(req: PHashRequest):
    """Compute multiple perceptual hashes for duplicate detection."""
    try:
        file_path = await acquire_image(req.image_path)

        with Image.open(file_path) as img:
            hashes = {
                "phash":     str(imagehash.phash(img)),
                "dhash":     str(imagehash.dhash(img)),
                "ahash":     str(imagehash.average_hash(img)),
                "whash":     str(imagehash.whash(img)),
            }

        if str(file_path).startswith(str(TEMP_DIR)):
            try: file_path.unlink()
            except: pass

        return {"image_id": req.image_id, **hashes}

    except Exception as e:
        logger.error(f"pHash failed for {req.image_id}: {e}")
        raise HTTPException(500, str(e))


@app.post("/sanitize")
async def sanitize_image(req: SanitizeRequest):
    """Strip metadata from an image and return the clean version."""
    try:
        file_path   = await acquire_image(req.image_path)
        output_path = req.output_path or str(
            TEMP_DIR / f"sanitized_{req.image_id}{file_path.suffix}"
        )

        # Default safe fields to keep
        keep = req.keep_fields or ["Orientation", "ColorSpace"]

        success = await exiftool.sanitize(str(file_path), output_path, keep)

        if not success:
            raise HTTPException(500, "Sanitization failed")

        return {
            "status":      "sanitized",
            "image_id":    req.image_id,
            "output_path": output_path,
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Sanitize failed for {req.image_id}: {e}")
        raise HTTPException(500, str(e))


@app.post("/validate")
async def validate_metadata(req: ExtractionRequest):
    """Validate metadata consistency and flag anomalies."""
    try:
        file_path = await acquire_image(req.image_path)
        raw       = await exiftool.extract_all(str(file_path), req.options)
        enhanced  = enhance_metadata(raw, file_path)

        issues    = []

        # Timestamp sanity
        dt_orig     = enhanced.get("EXIF", {}).get("DateTimeOriginal")
        dt_modified = enhanced.get("EXIF", {}).get("DateTime")
        if dt_orig and dt_modified:
            from datetime import datetime
            try:
                t_orig = datetime.strptime(dt_orig,     "%Y:%m:%d %H:%M:%S")
                t_mod  = datetime.strptime(dt_modified, "%Y:%m:%d %H:%M:%S")
                if t_mod < t_orig:
                    issues.append({"type": "timestamp_regression", "severity": "high",
                                   "detail": "Modified date precedes capture date"})
            except ValueError:
                issues.append({"type": "invalid_timestamp_format", "severity": "medium",
                               "detail": "Timestamp cannot be parsed"})

        # GPS plausibility
        lat = enhanced.get("Composite", {}).get("GPSLatitude")
        lon = enhanced.get("Composite", {}).get("GPSLongitude")
        if lat is not None and lon is not None:
            if not (-90 <= float(lat) <= 90 and -180 <= float(lon) <= 180):
                issues.append({"type": "invalid_gps_range", "severity": "high",
                               "detail": f"GPS out of valid range: {lat},{lon}"})

        # Dimension mismatch
        exif_w = enhanced.get("EXIF", {}).get("ExifImageWidth")
        exif_h = enhanced.get("EXIF", {}).get("ExifImageHeight")
        act_w  = enhanced.get("Computed", {}).get("ActualWidth")
        act_h  = enhanced.get("Computed", {}).get("ActualHeight")
        if exif_w and act_w and int(exif_w) != int(act_w):
            issues.append({"type": "dimension_mismatch", "severity": "medium",
                           "detail": f"EXIF dimensions {exif_w}x{exif_h} ≠ actual {act_w}x{act_h}"})

        sw_fp = fingerprint_software(enhanced)

        return {
            "image_id":            req.image_id,
            "issues":              issues,
            "issue_count":         len(issues),
            "software_fingerprint":sw_fp,
        }

    except Exception as e:
        raise HTTPException(500, str(e))


if __name__ == "__main__":
    uvicorn.run("main:app", host="0.0.0.0", port=8001, workers=WORKERS, log_level="info")
