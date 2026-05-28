"""
MetaIntel – Video Metadata Extraction (appended to metadata-service/main.py)
Adds the /extract-video endpoint powered by FFprobe + MediaInfo
"""
from __future__ import annotations

import asyncio
import json
import logging
import math
import re
import struct
import tempfile
from pathlib import Path
from typing import Optional

import aiohttp
from fastapi import HTTPException
from pydantic import BaseModel

logger = logging.getLogger("metadata-service.video")

# ─── Pydantic models ──────────────────────────────────────────────────────────
class VideoExtractionRequest(BaseModel):
    media_path:           str
    media_id:             str
    options:              dict = {}

# ─── FFprobe wrapper ──────────────────────────────────────────────────────────
async def run_ffprobe(file_path: str) -> dict:
    """Run ffprobe and return full JSON output."""
    cmd = [
        "ffprobe", "-v", "quiet",
        "-print_format", "json",
        "-show_format",
        "-show_streams",
        "-show_chapters",
        file_path,
    ]
    try:
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=60)
        if proc.returncode not in (0, 1):
            raise RuntimeError(f"ffprobe exited {proc.returncode}: {stderr.decode()}")
        return json.loads(stdout.decode("utf-8", errors="replace"))
    except asyncio.TimeoutError:
        raise RuntimeError("FFprobe timed out")
    except json.JSONDecodeError as e:
        raise RuntimeError(f"FFprobe JSON parse error: {e}")


async def run_mediainfo(file_path: str) -> dict:
    """Run mediainfo for supplemental metadata."""
    cmd = ["mediainfo", "--Output=JSON", file_path]
    try:
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.DEVNULL,
        )
        stdout, _ = await asyncio.wait_for(proc.communicate(), timeout=30)
        return json.loads(stdout.decode("utf-8", errors="replace"))
    except Exception:
        return {}


# ─── GPS atom parsing (QuickTime / MP4) ───────────────────────────────────────
def parse_quicktime_gps(tags: dict) -> Optional[tuple[float, float, Optional[float]]]:
    """
    Extract GPS from QuickTime/MP4 tags.
    Handles ISO 6709 format: "+51.5074-000.1278+012.300/"
    as well as individual tag fields.
    """
    # Try ISO 6709 string (common in Apple/Android videos)
    location = (
        tags.get("location")        or
        tags.get("com.apple.quicktime.location.ISO6709") or
        tags.get("Location")        or
        tags.get("GPS")             or
        tags.get("creation_location")
    )
    if location:
        try:
            # Pattern: +lat+lon+alt/ or +lat+lon/
            m = re.match(
                r'([+-]\d+\.?\d*)([+-]\d+\.?\d*)([+-]\d+\.?\d*)?/?',
                str(location).strip()
            )
            if m:
                lat = float(m.group(1))
                lon = float(m.group(2))
                alt = float(m.group(3)) if m.group(3) else None
                if -90 <= lat <= 90 and -180 <= lon <= 180:
                    return lat, lon, alt
        except (ValueError, AttributeError):
            pass

    # Try individual GPS fields (Android, some cameras)
    lat_str = tags.get("GPSLatitude")  or tags.get("gps_latitude")
    lon_str = tags.get("GPSLongitude") or tags.get("gps_longitude")
    if lat_str and lon_str:
        try:
            return float(lat_str), float(lon_str), float(tags.get("GPSAltitude") or 0) or None
        except (ValueError, TypeError):
            pass

    return None


def parse_gps_track(ffprobe_data: dict) -> list[dict]:
    """
    Attempt to extract a GPS track from video streams.
    Some cameras (GoPro, DJI, smartphones) embed GPS as data streams.
    """
    tracks = []
    for stream in ffprobe_data.get("streams", []):
        if stream.get("codec_type") == "data":
            tags = stream.get("tags", {})
            handler = tags.get("handler_name", "").lower()
            # GoPro GPMD, DJI, or generic GPS data streams
            if any(k in handler for k in ["gps", "gpmd", "tmcd", "meta"]):
                # These require binary parsing of the stream – signal to caller
                # that a GPS data stream exists for further processing
                tracks.append({
                    "stream_index": stream.get("index"),
                    "handler":      tags.get("handler_name"),
                    "codec_tag":    stream.get("codec_tag_string"),
                })
    return tracks


# ─── Software fingerprinting for video encoders ───────────────────────────────
VIDEO_ENCODER_FINGERPRINTS = {
    "handbrake":        {"category": "transcoder",        "risk": "medium"},
    "ffmpeg":           {"category": "open_source",        "risk": "low"},
    "adobe premiere":   {"category": "professional_nle",   "risk": "medium"},
    "davinci resolve":  {"category": "professional_nle",   "risk": "medium"},
    "final cut pro":    {"category": "professional_nle",   "risk": "medium"},
    "capcut":           {"category": "mobile_editor",      "risk": "medium"},
    "inshot":           {"category": "mobile_editor",      "risk": "medium"},
    "tiktok":           {"category": "social_media",       "risk": "medium"},
    "instagram":        {"category": "social_media",       "risk": "medium"},
    "snapchat":         {"category": "social_media",       "risk": "medium"},
    "obs studio":       {"category": "screen_recorder",    "risk": "low"},
    "bandicam":         {"category": "screen_recorder",    "risk": "low"},
    "deepfakelab":      {"category": "deepfake_tool",      "risk": "critical"},
    "faceswap":         {"category": "deepfake_tool",      "risk": "critical"},
    "roop":             {"category": "deepfake_tool",      "risk": "critical"},
    "reface":           {"category": "mobile_deepfake",    "risk": "critical"},
}

def fingerprint_video_software(ffprobe_data: dict, mediainfo_data: dict) -> dict:
    fields_to_check = []

    fmt_tags = ffprobe_data.get("format", {}).get("tags", {})
    for tag_val in [
        fmt_tags.get("encoder", ""),
        fmt_tags.get("software", ""),
        fmt_tags.get("handler_name", ""),
        fmt_tags.get("com.apple.quicktime.software", ""),
        fmt_tags.get("Encoded_Application", ""),
    ]:
        if tag_val:
            fields_to_check.append(str(tag_val).lower())

    # Also check per-stream encoder tags
    for stream in ffprobe_data.get("streams", []):
        st = stream.get("tags", {}).get("encoder", "")
        if st:
            fields_to_check.append(st.lower())

    detections = []
    for field_val in fields_to_check:
        for sw_key, sw_info in VIDEO_ENCODER_FINGERPRINTS.items():
            if sw_key in field_val:
                detections.append({
                    "software":   field_val,
                    "category":   sw_info["category"],
                    "risk_level": sw_info["risk"],
                })
                break

    return {
        "detections":  detections,
        "has_edits":   len(detections) > 0,
        "highest_risk":max(
            (d["risk_level"] for d in detections),
            key=lambda r: ["low","medium","high","critical"].index(r),
            default="none"
        ) if detections else "none",
    }


# ─── Main video extraction function ──────────────────────────────────────────
async def extract_video_metadata(media_path: str, media_id: str, options: dict) -> dict:
    ffprobe_data  = await run_ffprobe(media_path)
    mediainfo_data= await run_mediainfo(media_path)

    fmt     = ffprobe_data.get("format", {})
    fmt_tags= fmt.get("tags", {})
    streams = ffprobe_data.get("streams", [])
    chapters= ffprobe_data.get("chapters", [])

    # Find video and audio streams
    video_streams = [s for s in streams if s.get("codec_type") == "video"]
    audio_streams = [s for s in streams if s.get("codec_type") == "audio"]
    sub_streams   = [s for s in streams if s.get("codec_type") == "subtitle"]
    vs = video_streams[0] if video_streams else {}
    vs_tags = vs.get("tags", {})

    # Frame rate parsing
    def parse_rational(s: str) -> Optional[float]:
        if not s or s == "0/0": return None
        try:
            parts = s.split("/")
            if len(parts) == 2 and float(parts[1]) != 0:
                return float(parts[0]) / float(parts[1])
            return float(parts[0])
        except (ValueError, ZeroDivisionError):
            return None

    avg_fps = parse_rational(vs.get("avg_frame_rate", ""))
    r_fps   = parse_rational(vs.get("r_frame_rate", ""))

    # HDR detection
    color_transfer = vs.get("color_transfer", "")
    is_hdr = color_transfer in ("smpte2084", "arib-std-b67", "bt2020-10", "bt2020-12")

    # Audio stream details
    audio_details = []
    for a in audio_streams:
        a_tags = a.get("tags", {})
        audio_details.append({
            "index":        a.get("index"),
            "codec":        a.get("codec_name"),
            "codec_long":   a.get("codec_long_name"),
            "channels":     a.get("channels"),
            "channel_layout":a.get("channel_layout"),
            "sample_rate":  a.get("sample_rate"),
            "bitrate":      int(a.get("bit_rate", 0)) if a.get("bit_rate") else None,
            "language":     a_tags.get("language"),
            "title":        a_tags.get("title"),
        })

    # Subtitle stream details
    subtitle_details = []
    for s in sub_streams:
        subtitle_details.append({
            "index":    s.get("index"),
            "codec":    s.get("codec_name"),
            "language": s.get("tags", {}).get("language"),
        })

    # Chapter details
    chapter_details = []
    for ch in chapters:
        chapter_details.append({
            "id":         ch.get("id"),
            "start_time": float(ch.get("start_time", 0)),
            "end_time":   float(ch.get("end_time", 0)),
            "title":      ch.get("tags", {}).get("title"),
        })

    # GPS extraction
    all_tags = {**fmt_tags}
    for s in streams:
        all_tags.update(s.get("tags", {}))

    gps = parse_quicktime_gps(all_tags)
    gps_lat, gps_lon, gps_alt = gps if gps else (None, None, None)
    gps_track_streams = parse_gps_track(ffprobe_data)

    # Creation time
    creation_time_str = (
        fmt_tags.get("creation_time") or
        vs_tags.get("creation_time")  or
        fmt_tags.get("com.apple.quicktime.creationdate")
    )

    # Software fingerprint
    sw_fp = fingerprint_video_software(ffprobe_data, mediainfo_data)

    # Determine encoder
    encoder = (
        fmt_tags.get("encoder")      or
        fmt_tags.get("software")     or
        vs_tags.get("encoder")       or
        fmt_tags.get("com.apple.quicktime.software")
    )

    metadata_payload = {
        # Container
        "container_format":   fmt.get("format_name", "").split(",")[0],
        "format_long_name":   fmt.get("format_long_name"),
        "duration_seconds":   float(fmt.get("duration") or 0) or None,
        "bitrate_bps":        int(fmt.get("bit_rate") or 0) or None,
        "file_size_bytes":    int(fmt.get("size") or 0) or None,
        "nb_streams":         int(fmt.get("nb_streams") or 0) or None,

        # Video
        "video_codec":        vs.get("codec_name"),
        "video_codec_long":   vs.get("codec_long_name"),
        "video_profile":      vs.get("profile"),
        "width":              vs.get("width") or vs.get("coded_width"),
        "height":             vs.get("height") or vs.get("coded_height"),
        "display_aspect_ratio":vs.get("display_aspect_ratio"),
        "pix_fmt":            vs.get("pix_fmt"),
        "frame_rate":         vs.get("r_frame_rate"),
        "avg_frame_rate":     avg_fps,
        "nb_frames":          int(vs.get("nb_frames") or 0) or None,
        "video_bitrate_bps":  int(vs.get("bit_rate") or 0) or None,
        "color_space":        vs.get("color_space"),
        "color_transfer":     color_transfer,
        "color_primaries":    vs.get("color_primaries"),
        "is_hdr":             is_hdr,
        "rotation":           vs_tags.get("rotate") or
                              vs.get("tags", {}).get("rotate"),

        # Audio
        "audio_streams":      audio_details,
        "audio_track_count":  len(audio_details),
        "subtitle_streams":   subtitle_details,
        "chapters":           chapter_details,

        # Encoding metadata
        "encoder":            encoder,
        "creation_time":      creation_time_str,
        "make":               fmt_tags.get("com.apple.quicktime.make")      or
                              fmt_tags.get("make")                          or
                              all_tags.get("Make"),
        "model":              fmt_tags.get("com.apple.quicktime.model")     or
                              fmt_tags.get("model")                         or
                              all_tags.get("Model"),
        "software":           fmt_tags.get("com.apple.quicktime.software")  or
                              fmt_tags.get("software")                      or
                              encoder,
        "handler_name":       vs_tags.get("handler_name"),

        # GPS
        "gps_latitude":       gps_lat,
        "gps_longitude":      gps_lon,
        "gps_altitude":       gps_alt,
        "gps_track":          gps_track_streams if gps_track_streams else None,

        # Raw
        "raw_ffprobe":        ffprobe_data,
        "raw_container_tags": dict(fmt_tags),
        "raw_video_tags":     dict(vs_tags) if vs else {},
        "raw_audio_tags":     {str(i): a.get("tags", {}) for i, a in enumerate(audio_streams)},
    }

    return {
        "status":              "success",
        "media_id":            media_id,
        "metadata":            metadata_payload,
        "software_fingerprint":sw_fp,
        "gps_track_streams":   gps_track_streams,
    }


# ─── Register the endpoint on the existing FastAPI app ───────────────────────
# (This module is imported by main.py and the route is registered there)

async def register_video_routes(app):
    """Call this from main.py: await register_video_routes(app)"""
    from fastapi import HTTPException as FHE

    @app.post("/extract-video")
    async def extract_video_endpoint(req: VideoExtractionRequest):
        try:
            from .main import acquire_image  # reuse image acquisition
            file_path = await acquire_image(req.media_path)
            result    = await extract_video_metadata(str(file_path), req.media_id, req.options)
            # Cleanup temp download
            if str(file_path).startswith(str(tempfile.gettempdir())):
                try: file_path.unlink()
                except: pass
            return result
        except FHE:
            raise
        except Exception as e:
            logger.error(f"Video extraction failed for {req.media_id}: {e}", exc_info=True)
            raise FHE(500, f"Video extraction failed: {str(e)}")
