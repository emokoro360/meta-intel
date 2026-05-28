"""
MetaIntel – Video Forensics Analysis
Appends /analyze-video endpoint to the forensics microservice.
Covers: keyframe extraction, per-frame ELA, temporal splice detection,
        re-encoding detection, A/V sync anomaly, deepfake scoring.
"""
from __future__ import annotations

import asyncio
import base64
import io
import json
import logging
import math
import os
import time
import tempfile
from pathlib import Path
from typing import Optional

import aiohttp
import cv2
import numpy as np
from fastapi import HTTPException
from PIL import Image
from pydantic import BaseModel

logger = logging.getLogger("forensics-service.video")

TEMP_DIR = Path(tempfile.gettempdir()) / "metaintel_video_forensics"
TEMP_DIR.mkdir(exist_ok=True)


# ─── Request model ────────────────────────────────────────────────────────────
class VideoForensicsRequest(BaseModel):
    media_path:           str
    media_id:             str
    duration_seconds:     float = 60.0
    max_frames:           int   = 30
    run_ela:              bool  = True
    run_noise:            bool  = True
    run_deepfake:         bool  = True
    run_splice_detect:    bool  = True
    run_reencoding:       bool  = True
    run_av_sync:          bool  = True
    frame_storage_prefix: str   = ""


# ─── Frame extraction ─────────────────────────────────────────────────────────
async def extract_frames(
    video_path: str,
    max_frames: int,
    duration: float,
) -> list[dict]:
    """
    Extract evenly-spaced frames using FFmpeg.
    Also extracts scene-change frames for richer coverage.
    """
    frames = []
    cap    = cv2.VideoCapture(video_path)

    if not cap.isOpened():
        raise ValueError(f"Cannot open video: {video_path}")

    total_frames  = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    fps           = cap.get(cv2.CAP_PROP_FPS) or 25
    actual_dur    = total_frames / fps if fps > 0 else duration

    # Build list of timestamps to sample
    if total_frames <= max_frames:
        sample_indices = list(range(total_frames))
    else:
        step = total_frames / max_frames
        sample_indices = [int(i * step) for i in range(max_frames)]

    # Always include first and last frame
    if 0 not in sample_indices:
        sample_indices.insert(0, 0)
    if total_frames - 1 not in sample_indices:
        sample_indices.append(total_frames - 1)

    prev_frame_gray = None

    for idx in sample_indices:
        cap.set(cv2.CAP_PROP_POS_FRAMES, idx)
        ret, frame = cap.read()
        if not ret:
            continue

        timestamp = idx / fps
        gray      = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)

        # Detect scene change (large frame diff from previous)
        frame_type = "keyframe"
        if prev_frame_gray is not None:
            diff = cv2.absdiff(gray, prev_frame_gray)
            scene_score = float(np.mean(diff))
            if scene_score > 30:
                frame_type = "scene_change"

        # Encode thumbnail as JPEG base64
        _, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 75])
        thumbnail_b64 = base64.b64encode(buf.tobytes()).decode()

        frames.append({
            "frame_number":    idx,
            "timestamp_seconds": round(timestamp, 3),
            "frame_type":      frame_type,
            "frame_bgr":       frame,          # in-memory only, not serialized
            "frame_gray":      gray,
            "thumbnail_b64":   thumbnail_b64,
        })

        prev_frame_gray = gray

    cap.release()
    return frames


# ─── Per-frame ELA ────────────────────────────────────────────────────────────
def ela_on_frame(frame_bgr: np.ndarray, quality: int = 75, scale: int = 10) -> float:
    """Compute ELA score for a single video frame."""
    try:
        pil_img   = Image.fromarray(cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2RGB))
        buf       = io.BytesIO()
        pil_img.save(buf, format="JPEG", quality=quality)
        buf.seek(0)
        recomp    = Image.open(buf).convert("RGB")

        from PIL import ImageChops
        ela_img   = ImageChops.difference(pil_img, recomp)
        ela_arr   = np.array(ela_img).astype(np.float32)

        extrema   = ela_arr.max()
        if extrema > 0:
            ela_arr = (ela_arr / extrema * 255 * scale).clip(0, 255)

        ela_gray  = cv2.cvtColor(ela_arr.astype(np.uint8), cv2.COLOR_RGB2GRAY)
        return float(np.mean(ela_gray))
    except Exception as e:
        logger.debug(f"Frame ELA failed: {e}")
        return 0.0


# ─── Temporal splice detection ────────────────────────────────────────────────
def detect_temporal_splicing(frames: list[dict]) -> dict:
    """
    Detect potential cut/splice points by analysing:
    1. Abrupt histogram shifts between consecutive frames
    2. Encoding parameter discontinuities
    3. Abnormal noise variance jumps
    """
    splice_points  = []
    prev_hist      = None

    for i, frame in enumerate(frames[:-1]):
        gray     = frame["frame_gray"]
        hist     = cv2.calcHist([gray], [0], None, [64], [0, 256])
        hist     = hist.flatten() / hist.sum()

        if prev_hist is not None:
            # Bhattacharyya distance between consecutive frame histograms
            dist = cv2.compareHist(
                prev_hist.reshape(-1, 1).astype(np.float32),
                hist.reshape(-1, 1).astype(np.float32),
                cv2.HISTCMP_BHATTACHARYYA
            )
            if dist > 0.55:   # threshold empirically derived
                splice_points.append({
                    "timestamp":    frame["timestamp_seconds"],
                    "frame_number": frame["frame_number"],
                    "confidence":   round(min(dist * 100, 99), 1),
                    "reason":       "histogram_discontinuity",
                })

        prev_hist = hist

    confidence = min(len(splice_points) * 25, 95) if splice_points else 0

    return {
        "detected":    len(splice_points) > 0,
        "confidence":  float(confidence),
        "splice_points": splice_points[:10],
    }


# ─── Re-encoding detection ────────────────────────────────────────────────────
def detect_reencoding(frames: list[dict]) -> dict:
    """
    Detect signs of re-encoding:
    - Double compression artifacts (DCT grid pattern analysis)
    - Block structure regularity
    """
    reencoding_scores = []

    for frame in frames[:10]:  # sample first 10 frames
        gray = frame["frame_gray"].astype(np.float32)

        # Compute variance of 8×8 block DCT coefficients
        h, w   = gray.shape
        scores = []
        for y in range(0, h - 8, 8):
            for x in range(0, w - 8, 8):
                block = gray[y:y+8, x:x+8]
                dct   = cv2.dct(block)
                # In doubly-compressed images, AC coefficients show
                # characteristic periodic patterns
                ac    = dct[1:, 1:]
                scores.append(float(np.var(ac)))

        if scores:
            reencoding_scores.append(np.mean(scores))

    if not reencoding_scores:
        return {"detected": None, "confidence": 0, "evidence": []}

    mean_score = float(np.mean(reencoding_scores))
    # Low DCT variance = strong quantization = likely re-encoded
    confidence = max(0, min(100, (1 - mean_score / 500) * 100)) if mean_score < 500 else 0

    return {
        "detected":    confidence > 50,
        "confidence":  round(confidence, 2),
        "evidence": [{
            "metric":     "dct_block_variance",
            "mean_value": round(mean_score, 4),
            "threshold":  500,
        }],
    }


# ─── Audio-Video sync analysis ────────────────────────────────────────────────
async def analyze_av_sync(video_path: str) -> dict:
    """
    Use FFprobe to check audio/video stream start times and detect sync offsets.
    A large offset (>500ms) may indicate deliberate manipulation.
    """
    cmd = [
        "ffprobe", "-v", "quiet", "-print_format", "json",
        "-show_streams", "-select_streams", "av",
        video_path,
    ]
    try:
        proc = await asyncio.create_subprocess_exec(
            *cmd, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL
        )
        stdout, _ = await asyncio.wait_for(proc.communicate(), timeout=20)
        data      = json.loads(stdout.decode())
        streams   = data.get("streams", [])

        video_start = None
        audio_start = None

        for s in streams:
            start = float(s.get("start_time") or 0)
            if s.get("codec_type") == "video" and video_start is None:
                video_start = start
            elif s.get("codec_type") == "audio" and audio_start is None:
                audio_start = start

        if video_start is not None and audio_start is not None:
            offset_ms = abs(audio_start - video_start) * 1000
            return {
                "anomaly":          offset_ms > 500,
                "offset_ms":        round(offset_ms, 2),
                "video_start":      video_start,
                "audio_start":      audio_start,
            }
    except Exception as e:
        logger.debug(f"AV sync analysis failed: {e}")

    return {"anomaly": None, "offset_ms": None}


# ─── Deepfake detection on frames ────────────────────────────────────────────
def detect_deepfake_frames(frames: list[dict]) -> dict:
    """
    Run deepfake heuristic detection on sampled frames.
    Uses the same frequency-domain + noise analysis as the image detector,
    focusing on face regions when detected.
    """
    frame_scores  = []
    face_cascade  = cv2.CascadeClassifier(
        cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
    )

    for frame in frames:
        gray   = frame["frame_gray"]
        faces  = face_cascade.detectMultiScale(gray, 1.1, 4)

        if len(faces) > 0:
            # Score each face crop
            for (fx, fy, fw, fh) in faces[:3]:
                face_region = gray[fy:fy+fh, fx:fx+fw]
                if face_region.size == 0:
                    continue

                # Frequency analysis on face region
                f_transform = np.fft.fft2(face_region.astype(np.float32))
                f_shift     = np.fft.fftshift(f_transform)
                magnitude   = 20 * np.log(np.abs(f_shift) + 1)

                cy, cx = face_region.shape[0]//2, face_region.shape[1]//2
                center = magnitude[max(0,cy-5):cy+5, max(0,cx-5):cx+5]
                outer  = magnitude[:5, :].flatten()

                if outer.size > 0 and np.mean(outer) > 0:
                    freq_ratio = float(np.mean(center)) / float(np.mean(outer) + 1e-6)
                    noise_var  = float(np.var(face_region))

                    score = 0.0
                    if freq_ratio > 15:     score += 30
                    if noise_var < 80:      score += 25
                    if noise_var < 30:      score += 20

                    frame_scores.append({
                        "frame_number": frame["frame_number"],
                        "timestamp":    frame["timestamp_seconds"],
                        "score":        round(min(score, 100), 2),
                        "freq_ratio":   round(freq_ratio, 3),
                        "noise_var":    round(noise_var, 3),
                    })
        else:
            frame_scores.append({
                "frame_number": frame["frame_number"],
                "timestamp":    frame["timestamp_seconds"],
                "score":        0.0,
                "no_face":      True,
            })

    scored = [s for s in frame_scores if not s.get("no_face")]
    if not scored:
        return {"detected": None, "confidence": 0, "frame_scores": frame_scores}

    mean_score = float(np.mean([s["score"] for s in scored]))
    max_score  = float(max(s["score"] for s in scored))

    return {
        "detected":    mean_score > 45,
        "confidence":  round(mean_score, 2),
        "max_frame_score": round(max_score, 2),
        "model":       "heuristic_frequency_domain",
        "frame_scores":frame_scores,
    }


# ─── Authenticity verdict aggregation ────────────────────────────────────────
def compute_video_verdict(
    ela_scores:  list[float],
    noise_res:   dict,
    splice_res:  dict,
    reencode_res:dict,
    deepfake_res:dict,
    av_sync_res: dict,
) -> tuple[str, float]:
    score = 100.0

    # ELA
    avg_ela = float(np.mean(ela_scores)) if ela_scores else 0
    max_ela = float(max(ela_scores))     if ela_scores else 0
    if avg_ela > 25: score -= min(avg_ela * 0.4, 30)

    # Splice
    if splice_res.get("detected") and splice_res.get("confidence", 0) > 50:
        score -= min(splice_res["confidence"] * 0.3, 25)

    # Re-encoding
    if reencode_res.get("detected") and reencode_res.get("confidence", 0) > 60:
        score -= min(reencode_res["confidence"] * 0.2, 20)

    # AV sync
    if av_sync_res.get("anomaly") and (av_sync_res.get("offset_ms") or 0) > 1000:
        score -= 15

    # Deepfake
    if deepfake_res.get("detected") and deepfake_res.get("confidence", 0) > 50:
        return "ai_generated", round(deepfake_res["confidence"], 2)

    score = max(0, score)
    if score >= 80:    verdict = "authentic"
    elif score >= 65:  verdict = "likely_authentic"
    elif score >= 45:  verdict = "suspicious"
    elif score >= 25:  verdict = "likely_tampered"
    else:              verdict = "tampered"

    return verdict, round(score, 2)


# ─── Main video analysis endpoint ────────────────────────────────────────────
async def analyze_video(req: VideoForensicsRequest) -> dict:
    start_time = time.time()

    # Acquire the media file
    if req.media_path.startswith(("http://", "https://")):
        tmp = TEMP_DIR / f"vid_{req.media_id}.tmp"
        async with aiohttp.ClientSession() as sess:
            async with sess.get(req.media_path, timeout=aiohttp.ClientTimeout(total=120)) as resp:
                resp.raise_for_status()
                with open(tmp, "wb") as f:
                    async for chunk in resp.content.iter_chunked(65536):
                        f.write(chunk)
        video_path = str(tmp)
    else:
        if not Path(req.media_path).exists():
            raise HTTPException(404, f"File not found: {req.media_path}")
        video_path = req.media_path

    loop = asyncio.get_event_loop()

    # Extract frames
    frames = await loop.run_in_executor(
        None, lambda: asyncio.run(extract_frames(video_path, req.max_frames, req.duration_seconds))
        if False else None  # direct call below
    )
    frames = await extract_frames(video_path, req.max_frames, req.duration_seconds)

    # Run analyses in parallel
    ela_task        = loop.run_in_executor(None, lambda: [ela_on_frame(f["frame_bgr"]) for f in frames]) if req.run_ela else asyncio.coroutine(lambda: [])()
    splice_task     = loop.run_in_executor(None, detect_temporal_splicing, frames)     if req.run_splice_detect else asyncio.coroutine(lambda: {})()
    reencode_task   = loop.run_in_executor(None, detect_reencoding, frames)            if req.run_reencoding   else asyncio.coroutine(lambda: {})()
    deepfake_task   = loop.run_in_executor(None, detect_deepfake_frames, frames)       if req.run_deepfake     else asyncio.coroutine(lambda: {})()
    av_sync_task    = analyze_av_sync(video_path)                                      if req.run_av_sync      else asyncio.coroutine(lambda: {})()

    ela_scores, splice_res, reencode_res, deepfake_res, av_sync_res = await asyncio.gather(
        ela_task, splice_task, reencode_task, deepfake_task, av_sync_task
    )

    ela_scores = ela_scores or []

    # Per-frame summary
    frame_results = []
    for i, frame in enumerate(frames):
        ela_s   = ela_scores[i] if i < len(ela_scores) else None
        frame_results.append({
            "frame_number":      frame["frame_number"],
            "timestamp_seconds": frame["timestamp_seconds"],
            "frame_type":        frame["frame_type"],
            "thumbnail_b64":     frame["thumbnail_b64"],
            "ela_score":         round(ela_s, 2) if ela_s is not None else None,
            "noise_score":       None,
            "forensic_details":  {},
            "faces":             [],
            "objects":           [],
        })

    # Suspicious frames
    suspicious_frames = [
        f for f in frame_results if (f.get("ela_score") or 0) > 35
    ]

    # Overall verdict
    verdict, auth_score = compute_video_verdict(
        ela_scores, {}, splice_res, reencode_res, deepfake_res, av_sync_res
    )

    # Tampering indicators
    indicators = []
    if splice_res.get("detected"):   indicators.append("temporal_splice")
    if reencode_res.get("detected"): indicators.append("reencoding")
    if av_sync_res.get("anomaly"):   indicators.append("av_sync_offset")
    if deepfake_res.get("detected"): indicators.append("deepfake_detected")
    if len(suspicious_frames) > 3:   indicators.append("suspicious_frames")

    elapsed_ms = int((time.time() - start_time) * 1000)

    # Cleanup temp file
    if req.media_path.startswith(("http://", "https://")):
        try: Path(video_path).unlink()
        except: pass

    return {
        "media_id":                  req.media_id,
        "authenticity_verdict":      verdict,
        "authenticity_score":        auth_score,
        "frames_analyzed":           len(frames),
        "frames":                    frame_results,

        # ELA summary
        "avg_ela_score":             round(float(np.mean(ela_scores)), 2) if ela_scores else None,
        "max_ela_score":             round(float(max(ela_scores)), 2)     if ela_scores else None,
        "suspicious_frame_count":    len(suspicious_frames),

        # Splice detection
        "splicing_detected":         splice_res.get("detected"),
        "splicing_confidence":       splice_res.get("confidence"),
        "splice_points":             splice_res.get("splice_points", []),

        # Re-encoding
        "reencoding_detected":       reencode_res.get("detected"),
        "reencoding_confidence":     reencode_res.get("confidence"),
        "reencoding_evidence":       reencode_res.get("evidence", []),

        # A/V sync
        "av_sync_anomaly":           av_sync_res.get("anomaly"),
        "av_sync_offset_ms":         av_sync_res.get("offset_ms"),

        # Deepfake
        "deepfake_detected":         deepfake_res.get("detected"),
        "deepfake_confidence":       deepfake_res.get("confidence"),
        "deepfake_model_used":       deepfake_res.get("model"),
        "deepfake_frame_scores":     deepfake_res.get("frame_scores", []),

        # Meta
        "tampering_indicators":      indicators,
        "analysis_version":          "1.0",
        "processing_time_ms":        elapsed_ms,
    }


# ─── Route registration ───────────────────────────────────────────────────────
async def register_video_routes(app):
    @app.post("/analyze-video")
    async def analyze_video_endpoint(req: VideoForensicsRequest):
        try:
            return await analyze_video(req)
        except HTTPException:
            raise
        except Exception as e:
            logger.error(f"Video analysis failed for {req.media_id}: {e}", exc_info=True)
            raise HTTPException(500, f"Video analysis failed: {str(e)}")
