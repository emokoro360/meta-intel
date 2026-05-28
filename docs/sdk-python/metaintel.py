"""
MetaIntel Python SDK  –  v2.0  (Images + Videos)
Full sync client for the MetaIntel Platform API.

Quick start:
    from metaintel import MetaIntelClient, analyze_media

    client = MetaIntelClient(base_url="https://your-instance.com", api_key="mi_...")

    # Works for both images and videos
    result = analyze_media(client, "clip.mp4")
    print(result["type"])                                  # "video"
    print(result["summary"]["authenticity"])               # "authentic"
    print(result["forensics"]["deepfake_detected"])        # False
    print(result["forensics"]["frames_analyzed"])          # 48
    print(len(result["frames"]))                           # 48

    result2 = analyze_media(client, "photo.jpg")
    print(result2["type"])                                 # "image"
    print(result2["forensics"]["is_ai_generated"])         # False
"""
from __future__ import annotations

import time
from pathlib import Path
from typing import Any, Union

import httpx

__version__ = "2.0.0"
__all__      = ["MetaIntelClient", "MetaIntelError", "analyze_media", "analyze_image"]

VIDEO_EXTENSIONS = {
    ".mp4",".mov",".avi",".mkv",".webm",".mpeg",".mpg",
    ".3gp",".3g2",".flv",".wmv",".m4v",".mxf",".ts",
}


class MetaIntelError(Exception):
    def __init__(self, message: str, status_code: int = 0, data: Any = None):
        super().__init__(message)
        self.status_code = status_code
        self.data        = data


class _Http:
    def __init__(self, base_url: str, api_key: str | None,
                 token: str | None, timeout: float):
        self.base_url = base_url.rstrip("/") + "/api/v1"
        self.api_key  = api_key
        self.token    = token
        self._c       = httpx.Client(timeout=timeout, follow_redirects=True)

    def _h(self, extra: dict | None = None) -> dict:
        h = {"Accept": "application/json", "X-MetaIntel-SDK": f"python/{__version__}"}
        if self.api_key: h["X-API-Key"]     = self.api_key
        if self.token:   h["Authorization"] = f"Bearer {self.token}"
        if extra:        h.update(extra)
        return h

    @staticmethod
    def _check(r: httpx.Response) -> Any:
        try:    data = r.json()
        except: data = r.text
        if r.is_error:
            msg = (data.get("message") or data.get("error") or str(data)
                   if isinstance(data, dict) else str(data))
            raise MetaIntelError(msg, r.status_code, data)
        return data

    def get(self, p: str) -> Any:
        return self._check(self._c.get(f"{self.base_url}{p}", headers=self._h()))

    def post(self, p: str, json_body=None, files=None, data=None) -> Any:
        extra = {} if files else {"Content-Type": "application/json"}
        return self._check(self._c.post(f"{self.base_url}{p}",
            headers=self._h(extra), json=json_body, files=files, data=data))

    def delete(self, p: str) -> Any:
        return self._check(self._c.delete(f"{self.base_url}{p}", headers=self._h()))

    def close(self): self._c.close()
    def __enter__(self): return self
    def __exit__(self, *_): self.close()


class _Media:
    def __init__(self, h: _Http): self._h = h

    def upload(self, file, *, run_forensics=True, run_ai=True,
               run_geospatial=True, max_frames=48) -> dict:
        if isinstance(file, Path): file = open(file, "rb")
        name = getattr(file, "name", "upload.bin")
        if isinstance(file, bytes):
            f_tuple = ("upload.bin", file, "application/octet-stream")
        else:
            f_tuple = (name, file, "application/octet-stream")
        return self._h.post("/media/upload", files={"file": f_tuple},
            data={"run_forensics": "1" if run_forensics else "0",
                  "run_ai":        "1" if run_ai        else "0",
                  "run_geospatial":"1" if run_geospatial else "0",
                  "max_frames":    str(max_frames)})

    def get(self, mid: str) -> dict:        return self._h.get(f"/media/{mid}")
    def get_status(self, mid: str) -> dict: return self._h.get(f"/media/{mid}/status")
    def get_frames(self, mid: str, *, suspicious_only=False) -> dict:
        return self._h.get(f"/media/{mid}/frames{'?suspicious_only=1' if suspicious_only else ''}")
    def get_gps_track(self, mid: str) -> dict: return self._h.get(f"/media/{mid}/gps-track")
    def reprocess(self, mid: str, **opts) -> dict: return self._h.post(f"/media/{mid}/reprocess", json_body=opts)
    def sanitize(self, mid: str) -> dict:   return self._h.post(f"/media/{mid}/sanitize")
    def delete(self, mid: str) -> dict:     return self._h.delete(f"/media/{mid}")
    def list(self, *, media_type=None, status=None, page=1, per_page=20) -> dict:
        qs = f"?page={page}&per_page={per_page}"
        if media_type: qs += f"&type={media_type}"
        if status:     qs += f"&status={status}"
        return self._h.get(f"/media{qs}")

    def wait_for_completion(self, mid: str, *, interval=2.0, timeout=300.0) -> dict:
        start = time.time()
        while True:
            if time.time() - start > timeout:
                raise MetaIntelError("Processing timeout exceeded", 408)
            r = self.get_status(mid)
            if r.get("status") == "completed": return r
            if r.get("status") == "failed":
                raise MetaIntelError("Media processing failed", 500, {"media_id": mid})
            time.sleep(interval)


class _Forensics:
    def __init__(self, h: _Http): self._h = h
    def get(self, mid: str) -> dict:          return self._h.get(f"/forensics/{mid}")
    def reanalyze(self, mid: str, **opts):    return self._h.post(f"/forensics/{mid}/reanalyze", json_body=opts)
    def anomaly_summary(self) -> dict:        return self._h.get("/forensics/summary/anomalies")
    def suspicious_frames(self, mid: str):    return self._h.get(f"/forensics/{mid}/frames")
    def splice_map(self, mid: str) -> dict:   return self._h.get(f"/forensics/{mid}/splice-map")


class _Batches:
    def __init__(self, h: _Http): self._h = h
    def list(self):              return self._h.get("/batches")
    def get(self, bid: int):     return self._h.get(f"/batches/{bid}")
    def timeline(self, bid: int):return self._h.get(f"/batches/{bid}/timeline")
    def cancel(self, bid: int):  return self._h.post(f"/batches/{bid}/cancel")


class _Reports:
    def __init__(self, h: _Http): self._h = h

    def generate(self, title: str, media_ids: list[str], *,
                 format: str = "pdf", sections: list[str] | None = None) -> dict:
        return self._h.post("/reports/generate", json_body={
            "title": title, "image_ids": media_ids,
            "format": format,
            "sections": sections or ["metadata","forensics","gps","timeline"],
        })

    def get(self, uuid: str):             return self._h.get(f"/reports/{uuid}")
    def export_json(self, mid: str):      return self._h.get(f"/reports/export-json/{mid}")

    def wait_and_get_url(self, uuid: str, *, interval=3.0, timeout=300.0) -> str:
        start = time.time()
        while True:
            if time.time() - start > timeout: raise MetaIntelError("Report timeout", 408)
            r = self.get(uuid)
            if r.get("report", {}).get("status") == "completed" and r.get("download_url"):
                return r["download_url"]
            if r.get("report", {}).get("status") == "failed":
                raise MetaIntelError("Report generation failed")
            time.sleep(interval)


class MetaIntelClient:
    """
    MetaIntel Platform SDK  (Images + Videos)
    client = MetaIntelClient(base_url="https://...", api_key="mi_...")
    """
    def __init__(self, base_url: str, api_key: str | None = None,
                 token: str | None = None, timeout: float = 30.0):
        if not base_url: raise MetaIntelError("base_url is required")
        _h             = _Http(base_url, api_key, token, timeout)
        self.media     = _Media(_h)
        self.images    = self.media          # backwards compat alias
        self.forensics = _Forensics(_h)
        self.batches   = _Batches(_h)
        self.reports   = _Reports(_h)
        self.analytics = type("_Anl", (), {"dashboard": lambda s: _h.get("/analytics/dashboard")})()
        self.search    = type("_Src", (), {"search": lambda s, q, **p: _h.get(f"/search?q={q}")})()
        self._h        = _h

    def set_token(self, t: str) -> "MetaIntelClient":
        self._h.token = t; return self

    def close(self): self._h.close()
    def __enter__(self): return self
    def __exit__(self, *_): self.close()


def analyze_media(client: MetaIntelClient, file_path: Union[str, Path],
                  run_forensics: bool = True, run_ai: bool = True,
                  max_frames: int = 48) -> dict:
    """
    Upload any media file, wait for processing, return the complete analysis.

    Returns a dict with keys:
      type, media, summary, metadata_tree, forensics, gps, frames (video only)
    """
    p      = Path(file_path)
    is_vid = p.suffix.lower() in VIDEO_EXTENSIONS
    if not p.exists(): raise MetaIntelError(f"File not found: {file_path}")

    with open(p, "rb") as f:
        up = client.media.upload(f, run_forensics=run_forensics,
                                 run_ai=run_ai, max_frames=max_frames)

    mid     = up["media"]["id"]
    timeout = 300.0 if is_vid else 120.0
    client.media.wait_for_completion(mid, timeout=timeout,
                                     interval=3.0 if is_vid else 2.0)
    data     = client.media.get(mid)
    forensics = None
    try:
        forensics = client.forensics.get(mid).get("forensics")
    except MetaIntelError:
        pass

    return {
        "type":          data.get("type", "image"),
        "media":         data.get("media"),
        "summary":       data.get("summary"),
        "metadata_tree": data.get("metadata_tree"),
        "forensics":     forensics,
        "gps":           data.get("geojson"),
        "frames":        data.get("frames"),
    }


# Backwards-compatible alias
analyze_image = analyze_media


if __name__ == "__main__":
    import sys, json
    if len(sys.argv) < 4:
        print("Usage: python metaintel.py <base_url> <api_key> <file_path>")
        sys.exit(1)
    c = MetaIntelClient(base_url=sys.argv[1], api_key=sys.argv[2])
    r = analyze_media(c, sys.argv[3])
    print(json.dumps({
        "type":         r["type"],
        "filename":     r["media"]["original_filename"],
        "authenticity": r["summary"]["authenticity"],
        "anomalies":    r["summary"].get("anomaly_count", 0),
        "deepfake":     r["forensics"].get("deepfake_detected") if r["type"] == "video" else None,
    }, indent=2))
