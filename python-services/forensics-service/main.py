"""
MetaIntel - AI Forensics Microservice
Error Level Analysis, noise analysis, AI/deepfake detection, copy-move detection
"""
from __future__ import annotations

import asyncio
import base64
import io
import logging
import os
import time
import tempfile
from pathlib import Path
from typing import Optional

import aiohttp
import numpy as np
import uvicorn
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import cv2
from PIL import Image, ImageChops, ImageEnhance
import scipy.ndimage as ndimage

# ─── Optional heavy imports (TF/PyTorch) ─────────────────────────────────────
try:
    import torch
    import torchvision.transforms as transforms
    from torchvision import models
    TORCH_AVAILABLE = True
    DEVICE = "cuda" if torch.cuda.is_available() else "cpu"
    logger_msg = f"PyTorch available (device: {DEVICE})"
except ImportError:
    TORCH_AVAILABLE = False
    DEVICE = "cpu"
    logger_msg = "PyTorch not available – AI detection disabled"

logging.basicConfig(level=os.getenv("LOG_LEVEL", "INFO"))
logger = logging.getLogger("forensics-service")
logger.info(logger_msg)

app = FastAPI(
    title="MetaIntel Forensics Service",
    version="1.0.0",
    description="AI-powered image forensics and tampering detection",
)
app.add_middleware(CORSMiddleware, allow_origins=["*"], allow_methods=["*"], allow_headers=["*"])

TEMP_DIR     = Path(tempfile.gettempdir()) / "metaintel_forensics"
MODEL_PATH   = Path(os.getenv("MODEL_PATH", "/app/models"))
TEMP_DIR.mkdir(exist_ok=True)
MODEL_PATH.mkdir(exist_ok=True)


# ─── Request Models ───────────────────────────────────────────────────────────
class ForensicsRequest(BaseModel):
    image_path:     str
    image_id:       str
    run_ela:        bool = True
    run_noise:      bool = True
    run_copy_move:  bool = True
    run_ai:         bool = True
    run_steg:       bool = False
    ela_quality:    int  = 75       # JPEG quality for ELA (60-95)
    ela_scale:      int  = 10       # ELA amplification factor


# ─── Image Acquisition ────────────────────────────────────────────────────────
async def acquire_image(image_path: str) -> Path:
    if image_path.startswith(("http://", "https://")):
        tmp = TEMP_DIR / f"dl_{abs(hash(image_path))}.tmp"
        if not tmp.exists():
            async with aiohttp.ClientSession() as sess:
                async with sess.get(image_path, timeout=aiohttp.ClientTimeout(total=30)) as resp:
                    resp.raise_for_status()
                    with open(tmp, "wb") as f:
                        async for chunk in resp.content.iter_chunked(65536):
                            f.write(chunk)
        return tmp
    p = Path(image_path)
    if not p.exists():
        raise HTTPException(404, f"File not found: {image_path}")
    return p


# ─── Error Level Analysis ─────────────────────────────────────────────────────
def perform_ela(img_path: Path, quality: int = 75, scale: int = 10) -> dict:
    """
    Error Level Analysis: re-compress image at known quality and compare.
    Regions with high ELA signal may indicate tampering.
    """
    try:
        original  = Image.open(img_path).convert("RGB")

        # Re-save at known quality
        tmp_buf   = io.BytesIO()
        original.save(tmp_buf, format="JPEG", quality=quality)
        tmp_buf.seek(0)
        recompressed = Image.open(tmp_buf).convert("RGB")

        # Compute difference
        ela_img   = ImageChops.difference(original, recompressed)
        ela_arr   = np.array(ela_img).astype(np.float32)

        # Scale for visibility
        extrema   = ela_arr.max()
        if extrema > 0:
            ela_arr = (ela_arr / extrema * 255 * scale).clip(0, 255).astype(np.uint8)

        # Convert ELA image to base64 for storage
        ela_pil   = Image.fromarray(ela_arr)
        buf       = io.BytesIO()
        ela_pil.save(buf, format="PNG")
        ela_b64   = base64.b64encode(buf.getvalue()).decode()

        # Score: mean luminance of ELA image (higher = more suspicious)
        ela_gray  = cv2.cvtColor(ela_arr, cv2.COLOR_RGB2GRAY)
        score     = float(np.mean(ela_gray))

        # Detect suspicious regions (high ELA areas)
        threshold = np.percentile(ela_gray, 90)
        _, binary = cv2.threshold(ela_gray, int(threshold), 255, cv2.THRESH_BINARY)
        contours, _ = cv2.findContours(binary, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        regions = []
        h, w    = ela_gray.shape
        for cnt in contours:
            area = cv2.contourArea(cnt)
            if area > (w * h * 0.001):  # min 0.1% of image
                x, y, cw, ch = cv2.boundingRect(cnt)
                regions.append({
                    "x": int(x), "y": int(y),
                    "width": int(cw), "height": int(ch),
                    "area_ratio": round(area / (w * h), 4),
                })

        return {
            "score":        round(min(score * 2, 100), 2),  # normalize to 0-100
            "ela_image_b64":ela_b64,
            "regions":      regions[:20],  # top 20 suspicious regions
            "raw_max":      float(extrema),
        }

    except Exception as e:
        logger.error(f"ELA failed: {e}")
        return {"score": None, "ela_image_b64": None, "regions": [], "error": str(e)}


# ─── Noise Analysis ───────────────────────────────────────────────────────────
def analyze_noise(img_path: Path) -> dict:
    """
    Analyze noise patterns across image regions.
    Inconsistent noise can indicate compositing or splicing.
    """
    try:
        img_cv = cv2.imread(str(img_path))
        if img_cv is None:
            raise ValueError("cv2 could not read image")

        gray = cv2.cvtColor(img_cv, cv2.COLOR_BGR2GRAY).astype(np.float32)

        # High-pass filter to isolate noise
        blurred   = cv2.GaussianBlur(gray, (5, 5), 0)
        noise_map = gray - blurred

        h, w = gray.shape
        block_size = max(min(h, w) // 8, 32)

        # Analyze noise variance in blocks
        block_variances = []
        for y in range(0, h - block_size, block_size // 2):
            for x in range(0, w - block_size, block_size // 2):
                block = noise_map[y:y+block_size, x:x+block_size]
                var   = float(np.var(block))
                block_variances.append({
                    "x": x, "y": y,
                    "width": block_size, "height": block_size,
                    "variance": round(var, 4),
                })

        variances      = [b["variance"] for b in block_variances]
        mean_var       = float(np.mean(variances)) if variances else 0
        std_var        = float(np.std(variances))  if variances else 0

        # Inconsistency score: normalized std relative to mean
        inconsistency  = (std_var / mean_var * 100) if mean_var > 0 else 0

        # Flag suspicious blocks (outliers: > 2 std from mean)
        threshold_high = mean_var + 2 * std_var
        threshold_low  = mean_var - 2 * std_var
        suspicious     = [
            b for b in block_variances
            if b["variance"] > threshold_high or b["variance"] < threshold_low * 0.5
        ]

        return {
            "score":           round(min(inconsistency, 100), 2),
            "mean_variance":   round(mean_var, 4),
            "std_variance":    round(std_var, 4),
            "inconsistency":   round(inconsistency, 2),
            "suspicious_blocks": suspicious[:15],
            "block_count":     len(block_variances),
        }

    except Exception as e:
        logger.error(f"Noise analysis failed: {e}")
        return {"score": None, "error": str(e)}


# ─── Copy-Move Detection ──────────────────────────────────────────────────────
def detect_copy_move(img_path: Path) -> dict:
    """
    Detect copy-move forgery using SIFT feature matching.
    Cloned regions within the same image have similar local feature descriptors.
    """
    try:
        img_cv = cv2.imread(str(img_path))
        if img_cv is None:
            raise ValueError("Could not read image")

        gray = cv2.cvtColor(img_cv, cv2.COLOR_BGR2GRAY)

        # SIFT feature detection
        sift    = cv2.SIFT_create(nfeatures=500)
        kp, des = sift.detectAndCompute(gray, None)

        if des is None or len(kp) < 10:
            return {"detected": False, "confidence": 0, "regions": []}

        # FLANN-based matching (fast approximate nearest neighbor)
        index_params  = dict(algorithm=1, trees=5)  # FLANN_INDEX_KDTREE
        search_params = dict(checks=50)
        flann = cv2.FlannBasedMatcher(index_params, search_params)

        matches = flann.knnMatch(des, des, k=2)

        # Filter matches using Lowe's ratio test, excluding self-matches
        good_matches = []
        for pair in matches:
            if len(pair) >= 2:
                m, n = pair[0], pair[1]
                if (m.trainIdx != m.queryIdx and
                    m.distance < 0.7 * n.distance):
                    pt1 = kp[m.queryIdx].pt
                    pt2 = kp[m.trainIdx].pt
                    dist = np.sqrt((pt1[0]-pt2[0])**2 + (pt1[1]-pt2[1])**2)
                    if dist > 10:  # minimum spatial separation
                        good_matches.append((pt1, pt2, float(m.distance)))

        confidence = min(len(good_matches) / 20 * 100, 100)
        detected   = len(good_matches) >= 5

        regions = []
        if detected:
            for pt1, pt2, dist in good_matches[:20]:
                regions.append({
                    "source": {"x": int(pt1[0]), "y": int(pt1[1])},
                    "clone":  {"x": int(pt2[0]), "y": int(pt2[1])},
                    "match_distance": round(dist, 2),
                })

        return {
            "detected":    detected,
            "confidence":  round(confidence, 2),
            "match_count": len(good_matches),
            "regions":     regions,
        }

    except Exception as e:
        logger.error(f"Copy-move detection failed: {e}")
        return {"detected": None, "confidence": 0, "error": str(e)}


# ─── AI / Deepfake Detection ──────────────────────────────────────────────────
class AIDetector:
    """
    AI-generated image detector.
    Uses a fine-tuned EfficientNet or ViT model if available,
    falls back to heuristic-based detection.
    """

    def __init__(self):
        self.model      = None
        self.transform  = None
        self._load_model()

    def _load_model(self):
        if not TORCH_AVAILABLE:
            return

        model_file = MODEL_PATH / "ai_detector.pth"

        if model_file.exists():
            try:
                self.model = torch.load(model_file, map_location=DEVICE)
                self.model.eval()
                self.transform = transforms.Compose([
                    transforms.Resize((224, 224)),
                    transforms.ToTensor(),
                    transforms.Normalize([0.485, 0.456, 0.406],
                                         [0.229, 0.224, 0.225]),
                ])
                logger.info("AI detector model loaded from disk")
                return
            except Exception as e:
                logger.warning(f"Could not load model file: {e}")

        logger.info("No AI detector model found – using heuristic detection")

    def detect(self, img_path: Path) -> dict:
        if self.model is not None and TORCH_AVAILABLE:
            return self._model_detect(img_path)
        return self._heuristic_detect(img_path)

    def _model_detect(self, img_path: Path) -> dict:
        try:
            img    = Image.open(img_path).convert("RGB")
            tensor = self.transform(img).unsqueeze(0).to(DEVICE)

            with torch.no_grad():
                logits = self.model(tensor)
                probs  = torch.softmax(logits, dim=1).cpu().numpy()[0]

            # Assume binary classification: [real, ai_generated]
            ai_prob = float(probs[1]) if len(probs) > 1 else float(probs[0])

            return {
                "is_ai_generated": ai_prob > 0.5,
                "confidence":      round(ai_prob * 100, 2),
                "model":           "fine_tuned_classifier",
                "method":          "deep_learning",
            }
        except Exception as e:
            logger.error(f"Model detection failed: {e}")
            return self._heuristic_detect(img_path)

    def _heuristic_detect(self, img_path: Path) -> dict:
        """
        Heuristic AI image detection based on:
        1. Unnatural uniformity in noise patterns
        2. Perfect symmetry artefacts
        3. Frequency domain analysis (GAN artefacts in FFT)
        """
        try:
            img_cv   = cv2.imread(str(img_path))
            if img_cv is None:
                return {"is_ai_generated": None, "confidence": 0, "method": "heuristic"}

            gray     = cv2.cvtColor(img_cv, cv2.COLOR_BGR2GRAY).astype(np.float32)
            h, w     = gray.shape
            score    = 0.0

            # ── Test 1: Frequency domain analysis (GAN artefacts) ────────────
            f_transform = np.fft.fft2(gray)
            f_shift     = np.fft.fftshift(f_transform)
            magnitude   = 20 * np.log(np.abs(f_shift) + 1)

            # GAN images often have unusual frequency patterns
            center_y, center_x = h // 2, w // 2
            center_region = magnitude[center_y-20:center_y+20, center_x-20:center_x+20]
            outer_region  = np.concatenate([
                magnitude[:20, :].flatten(),
                magnitude[-20:, :].flatten(),
            ])
            freq_ratio    = np.mean(center_region) / (np.mean(outer_region) + 1e-6)
            if freq_ratio > 15:
                score += 25  # Unusual frequency concentration

            # ── Test 2: Noise uniformity (real photos have camera noise) ─────
            blurred   = cv2.GaussianBlur(gray, (3, 3), 0)
            noise_map = gray - blurred
            noise_var = np.var(noise_map)
            if noise_var < 2.0:  # suspiciously clean
                score += 20

            # ── Test 3: Color channel analysis ────────────────────────────────
            b, g, r   = cv2.split(img_cv.astype(np.float32))
            corr_rg   = np.corrcoef(r.flatten(), g.flatten())[0,1]
            corr_rb   = np.corrcoef(r.flatten(), b.flatten())[0,1]
            if corr_rg > 0.99 and corr_rb > 0.99:
                score += 15  # Suspiciously high channel correlation

            # ── Test 4: Edge coherence ────────────────────────────────────────
            edges = cv2.Canny(cv2.convertScaleAbs(gray), 50, 150)
            edge_density = np.sum(edges > 0) / (h * w)
            if 0.01 < edge_density < 0.05:  # AI images often have moderate edges
                score += 10

            # ── Test 5: JPEG artifact pattern ────────────────────────────────
            if img_path.suffix.lower() in [".jpg", ".jpeg"]:
                dct_score = self._analyze_dct_artifacts(gray)
                if dct_score > 50:
                    score -= 15  # Strong JPEG artifacts suggest real photo

            confidence = min(score, 100)

            return {
                "is_ai_generated": confidence > 45,
                "confidence":      round(confidence, 2),
                "method":          "heuristic",
                "model":           "frequency_domain_analysis",
                "details": {
                    "freq_ratio":  round(float(freq_ratio), 3),
                    "noise_var":   round(float(noise_var), 4),
                    "channel_corr":round(float(corr_rg), 4),
                    "edge_density":round(float(edge_density), 4),
                },
            }

        except Exception as e:
            logger.error(f"Heuristic detection failed: {e}")
            return {"is_ai_generated": None, "confidence": 0, "method": "error", "error": str(e)}

    def _analyze_dct_artifacts(self, gray: np.ndarray) -> float:
        """Check for natural JPEG blocking artifacts (absent in AI images)."""
        h, w = gray.shape
        block_diffs = []
        for y in range(0, h - 8, 8):
            for x in range(0, w - 8, 8):
                if x + 8 < w:
                    diff = abs(float(np.mean(gray[y:y+8, x:x+8])) -
                               float(np.mean(gray[y:y+8, x+8:x+16])))
                    block_diffs.append(diff)
        return float(np.mean(block_diffs)) * 10 if block_diffs else 0


ai_detector = AIDetector()


# ─── Face Detection ───────────────────────────────────────────────────────────
def detect_faces(img_path: Path) -> list[dict]:
    try:
        img_cv  = cv2.imread(str(img_path))
        gray    = cv2.cvtColor(img_cv, cv2.COLOR_BGR2GRAY)

        # Use OpenCV's DNN-based face detector for better accuracy
        model_file  = MODEL_PATH / "opencv_face_detector_uint8.pb"
        config_file = MODEL_PATH / "opencv_face_detector.pbtxt"

        if model_file.exists() and config_file.exists():
            net = cv2.dnn.readNetFromTensorflow(str(model_file), str(config_file))
            blob = cv2.dnn.blobFromImage(img_cv, 1.0, (300, 300), [104, 117, 123])
            net.setInput(blob)
            detections = net.forward()
            faces = []
            h, w = img_cv.shape[:2]
            for i in range(detections.shape[2]):
                conf = float(detections[0, 0, i, 2])
                if conf > 0.5:
                    box = detections[0, 0, i, 3:7] * [w, h, w, h]
                    x1, y1, x2, y2 = box.astype(int)
                    faces.append({
                        "x": int(x1), "y": int(y1),
                        "width": int(x2-x1), "height": int(y2-y1),
                        "confidence": round(conf, 3),
                    })
            return faces
        else:
            # Fallback: Haar cascades
            cascade = cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_frontalface_default.xml")
            detected = cascade.detectMultiScale(gray, 1.1, 4)
            return [
                {"x": int(x), "y": int(y), "width": int(w), "height": int(h), "confidence": 0.7}
                for x, y, w, h in detected
            ]
    except Exception as e:
        logger.error(f"Face detection failed: {e}")
        return []


# ─── Authenticity Score Aggregation ──────────────────────────────────────────
def compute_authenticity_verdict(results: dict) -> tuple[str, float]:
    """Aggregate individual analysis scores into an overall verdict."""
    score = 100.0  # start with perfect authenticity

    ela    = results.get("ela", {})
    noise  = results.get("noise", {})
    cm     = results.get("copy_move", {})
    ai     = results.get("ai", {})

    if ela.get("score") and ela["score"] > 30:
        score -= min(ela["score"] * 0.5, 40)

    if noise.get("score") and noise["score"] > 40:
        score -= min(noise["score"] * 0.3, 25)

    if cm.get("detected") and cm.get("confidence", 0) > 50:
        score -= min(cm["confidence"] * 0.4, 30)

    if ai.get("is_ai_generated") is True:
        return "ai_generated", round(ai.get("confidence", 90), 2)

    score = max(0, score)

    if score >= 80:   verdict = "authentic"
    elif score >= 65: verdict = "likely_authentic"
    elif score >= 45: verdict = "suspicious"
    elif score >= 25: verdict = "likely_tampered"
    else:             verdict = "tampered"

    return verdict, round(score, 2)


# ─── Main Forensics Endpoint ──────────────────────────────────────────────────
@app.get("/health")
async def health():
    return {
        "status": "ok",
        "service": "forensics-service",
        "torch_available": TORCH_AVAILABLE,
        "device": DEVICE,
    }


@app.post("/analyze")
async def analyze_image(req: ForensicsRequest):
    """Run full forensic analysis pipeline on an image."""
    start_time = time.time()

    try:
        file_path = await acquire_image(req.image_path)
        results   = {}

        # Run analyses in parallel where possible
        loop = asyncio.get_event_loop()

        tasks = []
        if req.run_ela:
            tasks.append(("ela",       loop.run_in_executor(None, perform_ela,        file_path, req.ela_quality, req.ela_scale)))
        if req.run_noise:
            tasks.append(("noise",     loop.run_in_executor(None, analyze_noise,      file_path)))
        if req.run_copy_move:
            tasks.append(("copy_move", loop.run_in_executor(None, detect_copy_move,   file_path)))
        if req.run_ai:
            tasks.append(("ai",        loop.run_in_executor(None, ai_detector.detect, file_path)))

        # Await all
        for key, task in tasks:
            results[key] = await task

        # Face detection (always run)
        results["faces"] = await loop.run_in_executor(None, detect_faces, file_path)

        verdict, auth_score = compute_authenticity_verdict(results)

        # Cleanup temp
        if str(file_path).startswith(str(TEMP_DIR)):
            try: file_path.unlink()
            except: pass

        elapsed_ms = int((time.time() - start_time) * 1000)

        return {
            "image_id":          req.image_id,
            "authenticity_verdict": verdict,
            "authenticity_score": auth_score,

            "ela_performed":     req.run_ela,
            "ela_score":         results.get("ela", {}).get("score"),
            "ela_image_b64":     results.get("ela", {}).get("ela_image_b64"),
            "ela_regions":       results.get("ela", {}).get("regions", []),

            "noise_analysis_performed": req.run_noise,
            "noise_score":       results.get("noise", {}).get("score"),
            "noise_map":         results.get("noise", {}).get("suspicious_blocks", []),

            "copy_move_detected":    results.get("copy_move", {}).get("detected"),
            "copy_move_confidence":  results.get("copy_move", {}).get("confidence"),
            "copy_move_regions":     results.get("copy_move", {}).get("regions", []),

            "ai_detection_performed":req.run_ai,
            "is_ai_generated":       results.get("ai", {}).get("is_ai_generated"),
            "ai_confidence":         results.get("ai", {}).get("confidence"),
            "ai_model_used":         results.get("ai", {}).get("model"),
            "ai_detection_details":  results.get("ai", {}).get("details"),

            "faces_detected":    results.get("faces", []),
            "face_count":        len(results.get("faces", [])),

            "tampering_indicators": [
                k for k, v in {
                    "ela":       results.get("ela",        {}).get("score", 0) or 0 > 30,
                    "noise":     results.get("noise",      {}).get("score", 0) or 0 > 40,
                    "copy_move": results.get("copy_move",  {}).get("detected", False),
                }.items() if v
            ],

            "analysis_version":  "1.0",
            "processing_time_ms":elapsed_ms,
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Analysis failed for {req.image_id}: {e}", exc_info=True)
        raise HTTPException(500, f"Analysis failed: {str(e)}")


if __name__ == "__main__":
    uvicorn.run("main:app", host="0.0.0.0", port=8002,
                workers=int(os.getenv("WORKERS", 2)), log_level="info")
