"""
MetaIntel - Geospatial Intelligence Microservice
GPS extraction, reverse geocoding, movement reconstruction, anomaly detection
"""
from __future__ import annotations

import asyncio
import logging
import math
import os
from datetime import datetime
from typing import Optional

import aiohttp
import uvicorn
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from geopy.geocoders import Nominatim, GoogleV3
from geopy.adapters import AioHTTPAdapter
from pydantic import BaseModel

logging.basicConfig(level=os.getenv("LOG_LEVEL", "INFO"))
logger = logging.getLogger("geospatial-service")

app = FastAPI(
    title="MetaIntel Geospatial Service",
    version="1.0.0",
    description="GPS intelligence, reverse geocoding, and movement analysis",
)
app.add_middleware(CORSMiddleware, allow_origins=["*"], allow_methods=["*"], allow_headers=["*"])

NOMINATIM_UA    = os.getenv("NOMINATIM_USER_AGENT", "MetaIntel/1.0")
GEOCODING_PROVIDER = os.getenv("GEOCODING_PROVIDER", "nominatim")
MAPBOX_TOKEN    = os.getenv("MAPBOX_TOKEN", "")
GOOGLE_API_KEY  = os.getenv("GOOGLE_MAPS_API_KEY", "")


# ─── Models ───────────────────────────────────────────────────────────────────
class GPSProcessRequest(BaseModel):
    image_id:   str
    gps_data:   dict         # raw GPS tags from ExifTool
    timestamp:  Optional[str] = None

class MovementAnalysisRequest(BaseModel):
    waypoints: list[dict]    # list of {image_id, lat, lon, timestamp}

class GeoJSONRequest(BaseModel):
    images: list[dict]       # list of {image_id, lat, lon, timestamp, filename}


# ─── Coordinate Parsing ───────────────────────────────────────────────────────
def parse_dms_to_decimal(dms_str: str, ref: str) -> Optional[float]:
    """Convert DMS (degrees°minutes'seconds") to decimal degrees."""
    try:
        # ExifTool -n (numeric) already returns decimal
        val = float(dms_str)
        if ref in ("S", "W"):
            val = -val
        return val
    except (ValueError, TypeError):
        pass

    # Manual DMS parsing
    try:
        parts = str(dms_str).replace("°", " ").replace("'", " ").replace('"', " ").split()
        deg   = float(parts[0])
        mins  = float(parts[1]) if len(parts) > 1 else 0
        secs  = float(parts[2]) if len(parts) > 2 else 0
        decimal = deg + mins / 60 + secs / 3600
        if ref in ("S", "W"):
            decimal = -decimal
        return decimal
    except Exception:
        return None


def extract_gps_coords(gps_data: dict) -> Optional[tuple[float, float, Optional[float]]]:
    """
    Extract normalized lat/lon/alt from raw GPS tag dict.
    Handles ExifTool composite format and raw EXIF format.
    """
    # Try composite (already decimal)
    lat = gps_data.get("Composite:GPSLatitude") or gps_data.get("GPSLatitude")
    lon = gps_data.get("Composite:GPSLongitude") or gps_data.get("GPSLongitude")

    if lat is not None and lon is not None:
        try:
            lat_f = float(str(lat).split()[0])
            lon_f = float(str(lon).split()[0])
            if "S" in str(lat): lat_f = -lat_f
            if "W" in str(lon): lon_f = -lon_f
            alt = gps_data.get("GPSAltitude")
            alt_f = float(str(alt).split()[0]) if alt else None
            return lat_f, lon_f, alt_f
        except (ValueError, TypeError):
            pass

    # Try raw DMS
    lat_dms = gps_data.get("GPS:GPSLatitude")
    lon_dms = gps_data.get("GPS:GPSLongitude")
    lat_ref = gps_data.get("GPS:GPSLatitudeRef", "N")
    lon_ref = gps_data.get("GPS:GPSLongitudeRef", "E")

    if lat_dms and lon_dms:
        lat_f = parse_dms_to_decimal(lat_dms, lat_ref)
        lon_f = parse_dms_to_decimal(lon_dms, lon_ref)
        if lat_f is not None and lon_f is not None:
            alt     = gps_data.get("GPS:GPSAltitude")
            alt_ref = gps_data.get("GPS:GPSAltitudeRef", "0")
            alt_f   = None
            if alt:
                alt_f = float(str(alt).split()[0])
                if alt_ref == "1": alt_f = -alt_f
            return lat_f, lon_f, alt_f

    return None


# ─── Reverse Geocoding ────────────────────────────────────────────────────────
async def reverse_geocode_nominatim(lat: float, lon: float) -> dict:
    """Reverse geocode via Nominatim (OpenStreetMap) - free, no API key needed."""
    try:
        url    = f"https://nominatim.openstreetmap.org/reverse"
        params = {
            "lat":            lat,
            "lon":            lon,
            "format":         "json",
            "addressdetails": 1,
            "zoom":           16,
        }
        headers = {"User-Agent": NOMINATIM_UA}

        async with aiohttp.ClientSession() as session:
            async with session.get(url, params=params, headers=headers,
                                   timeout=aiohttp.ClientTimeout(total=10)) as resp:
                if resp.status != 200:
                    return {}
                data = await resp.json()

        addr = data.get("address", {})
        return {
            "country_code": addr.get("country_code", "").upper(),
            "country":      addr.get("country"),
            "state":        addr.get("state") or addr.get("province"),
            "city":         addr.get("city") or addr.get("town") or addr.get("village"),
            "district":     addr.get("suburb") or addr.get("neighbourhood"),
            "street":       " ".join(filter(None, [addr.get("road"), addr.get("house_number")])),
            "postal_code":  addr.get("postcode"),
            "place_name":   data.get("display_name"),
            "full_address_components": addr,
        }
    except Exception as e:
        logger.warning(f"Nominatim geocoding failed: {e}")
        return {}


async def reverse_geocode_mapbox(lat: float, lon: float) -> dict:
    """Reverse geocode via Mapbox API."""
    if not MAPBOX_TOKEN:
        return await reverse_geocode_nominatim(lat, lon)
    try:
        url = f"https://api.mapbox.com/geocoding/v5/mapbox.places/{lon},{lat}.json"
        params = {"access_token": MAPBOX_TOKEN, "types": "country,region,place,locality,neighborhood,address"}

        async with aiohttp.ClientSession() as session:
            async with session.get(url, params=params, timeout=aiohttp.ClientTimeout(total=10)) as resp:
                data = await resp.json()

        features = data.get("features", [])
        result   = {}

        for feature in features:
            context_type = feature.get("place_type", [""])[0]
            props        = feature.get("properties", {})

            if context_type == "country":
                result["country"]      = feature["text"]
                result["country_code"] = props.get("short_code", "").upper()
            elif context_type in ("region", "state"):
                result["state"] = feature["text"]
            elif context_type in ("place", "locality"):
                result["city"] = feature["text"]
            elif context_type == "neighborhood":
                result["district"] = feature["text"]
            elif context_type == "address":
                result["street"] = feature.get("place_name", "")

        if features:
            result["place_name"] = features[0].get("place_name")

        return result
    except Exception as e:
        logger.warning(f"Mapbox geocoding failed: {e}")
        return await reverse_geocode_nominatim(lat, lon)


async def reverse_geocode(lat: float, lon: float) -> dict:
    """Dispatch to configured geocoding provider."""
    if GEOCODING_PROVIDER == "mapbox" and MAPBOX_TOKEN:
        return await reverse_geocode_mapbox(lat, lon)
    return await reverse_geocode_nominatim(lat, lon)


# ─── Distance & Bearing ───────────────────────────────────────────────────────
def haversine_km(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Calculate great-circle distance in km."""
    R   = 6371
    φ1  = math.radians(lat1)
    φ2  = math.radians(lat2)
    Δφ  = math.radians(lat2 - lat1)
    Δλ  = math.radians(lon2 - lon1)
    a   = math.sin(Δφ/2)**2 + math.cos(φ1) * math.cos(φ2) * math.sin(Δλ/2)**2
    return R * 2 * math.asin(math.sqrt(a))


def compute_bearing(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Compute bearing in degrees (0=North, 90=East)."""
    φ1, φ2 = math.radians(lat1), math.radians(lat2)
    Δλ     = math.radians(lon2 - lon1)
    x      = math.sin(Δλ) * math.cos(φ2)
    y      = math.cos(φ1)*math.sin(φ2) - math.sin(φ1)*math.cos(φ2)*math.cos(Δλ)
    return (math.degrees(math.atan2(x, y)) + 360) % 360


# ─── Movement Analysis ────────────────────────────────────────────────────────
def analyze_movement(waypoints: list[dict]) -> dict:
    """
    Analyze movement path from multiple images.
    Detect impossible speeds, timezone jumps, suspicious patterns.
    """
    if len(waypoints) < 2:
        return {"segments": [], "anomalies": [], "total_distance_km": 0}

    # Sort by timestamp
    sorted_wp = sorted(
        [w for w in waypoints if w.get("timestamp") and w.get("lat") and w.get("lon")],
        key=lambda x: x["timestamp"]
    )

    segments  = []
    anomalies = []
    total_km  = 0

    for i in range(len(sorted_wp) - 1):
        wp1 = sorted_wp[i]
        wp2 = sorted_wp[i + 1]

        dist_km = haversine_km(wp1["lat"], wp1["lon"], wp2["lat"], wp2["lon"])
        bearing = compute_bearing(wp1["lat"], wp1["lon"], wp2["lat"], wp2["lon"])

        # Time delta
        try:
            t1 = datetime.fromisoformat(wp1["timestamp"])
            t2 = datetime.fromisoformat(wp2["timestamp"])
            dt_hours = (t2 - t1).total_seconds() / 3600
        except Exception:
            dt_hours = None

        speed_kmh = (dist_km / dt_hours) if dt_hours and dt_hours > 0 else None

        segment = {
            "from":       wp1["image_id"],
            "to":         wp2["image_id"],
            "distance_km":round(dist_km, 3),
            "bearing":    round(bearing, 1),
            "dt_hours":   round(dt_hours, 3) if dt_hours else None,
            "speed_kmh":  round(speed_kmh, 1) if speed_kmh else None,
        }
        segments.append(segment)
        total_km += dist_km

        # ── Anomaly detection ─────────────────────────────────────────────────
        if speed_kmh and speed_kmh > 900:  # Faster than commercial jet
            anomalies.append({
                "type":    "impossible_speed",
                "severity":"high",
                "detail":  f"Speed of {speed_kmh:.0f} km/h between images – physically impossible",
                "segment": segment,
            })
        elif speed_kmh and speed_kmh > 400:
            anomalies.append({
                "type":    "suspicious_speed",
                "severity":"medium",
                "detail":  f"Speed of {speed_kmh:.0f} km/h – requires air travel",
                "segment": segment,
            })

        if dt_hours and dt_hours < 0:
            anomalies.append({
                "type":    "timestamp_regression",
                "severity":"high",
                "detail":  "Timestamp goes backward between consecutive GPS points",
                "segment": segment,
            })

        if dist_km > 5000:
            anomalies.append({
                "type":    "large_location_jump",
                "severity":"medium",
                "detail":  f"Location jump of {dist_km:.0f} km in one step",
                "segment": segment,
            })

    return {
        "waypoints":          sorted_wp,
        "segments":           segments,
        "anomalies":          anomalies,
        "total_distance_km":  round(total_km, 2),
        "average_speed_kmh":  round(total_km / sum(
            s["dt_hours"] for s in segments if s.get("dt_hours") and s["dt_hours"] > 0
        ), 1) if segments else None,
    }


# ─── GeoJSON Builder ──────────────────────────────────────────────────────────
def build_geojson_collection(images: list[dict]) -> dict:
    """Build a GeoJSON FeatureCollection from image list."""
    features = []

    for img in images:
        if not img.get("lat") or not img.get("lon"):
            continue
        features.append({
            "type": "Feature",
            "geometry": {
                "type":        "Point",
                "coordinates": [img["lon"], img["lat"]],
            },
            "properties": {k: v for k, v in img.items() if k not in ("lat", "lon")},
        })

    # Build path LineString if multiple points
    if len(features) >= 2:
        coords = [[img["lon"], img["lat"]] for img in images if img.get("lat")]
        features.append({
            "type": "Feature",
            "geometry": {"type": "LineString", "coordinates": coords},
            "properties": {"type": "movement_path"},
        })

    return {"type": "FeatureCollection", "features": features}


# ─── API Endpoints ────────────────────────────────────────────────────────────
@app.get("/health")
async def health():
    return {"status": "ok", "service": "geospatial-service", "provider": GEOCODING_PROVIDER}


@app.post("/process-gps")
async def process_gps(req: GPSProcessRequest):
    """Extract, normalize, and reverse-geocode GPS data from an image."""
    coords = extract_gps_coords(req.gps_data)

    if not coords:
        return {"image_id": req.image_id, "has_gps": False}

    lat, lon, alt = coords

    # Validation
    if not (-90 <= lat <= 90 and -180 <= lon <= 180):
        return {
            "image_id": req.image_id,
            "has_gps":  True,
            "error":    "GPS coordinates out of valid range",
            "latitude": lat, "longitude": lon,
        }

    # Reverse geocode
    address = await reverse_geocode(lat, lon)

    raw = req.gps_data
    return {
        "image_id":  req.image_id,
        "has_gps":   True,
        "latitude":  round(lat, 7),
        "longitude": round(lon, 7),
        "altitude":  round(alt, 2) if alt else None,
        "altitude_ref":    raw.get("GPS:GPSAltitudeRef"),
        "direction":       raw.get("GPS:GPSImgDirection"),
        "direction_ref":   raw.get("GPS:GPSImgDirectionRef"),
        "speed":           raw.get("GPS:GPSSpeed"),
        "speed_ref":       raw.get("GPS:GPSSpeedRef"),
        "gps_timestamp":   raw.get("GPS:GPSTimeStamp"),
        "gps_datestamp":   raw.get("GPS:GPSDateStamp"),
        "map_datum":       raw.get("GPS:GPSMapDatum"),
        "dop":             raw.get("GPS:GPSDOP"),
        "measure_mode":    raw.get("GPS:GPSMeasureMode"),
        "satellites":      raw.get("GPS:GPSSatellites"),
        "processing_method": raw.get("GPS:GPSProcessingMethod"),
        **address,
    }


@app.post("/reverse-geocode")
async def geocode_coordinates(lat: float, lon: float):
    """Reverse geocode a coordinate pair."""
    address = await reverse_geocode(lat, lon)
    return {"latitude": lat, "longitude": lon, **address}


@app.post("/analyze-movement")
async def analyze_movement_route(req: MovementAnalysisRequest):
    """Analyze movement path from multiple GPS waypoints."""
    result = analyze_movement(req.waypoints)
    return result


@app.post("/geojson")
async def build_geojson(req: GeoJSONRequest):
    """Build GeoJSON FeatureCollection from image list."""
    return build_geojson_collection(req.images)


@app.get("/timezone")
async def timezone_for_coords(lat: float, lon: float):
    """Get timezone name for a coordinate pair."""
    try:
        url = f"http://api.geonames.org/timezoneJSON?lat={lat}&lng={lon}&username=metaintel"
        async with aiohttp.ClientSession() as sess:
            async with sess.get(url, timeout=aiohttp.ClientTimeout(total=5)) as resp:
                data = await resp.json()
        return {"timezone": data.get("timezoneId"), "offset": data.get("rawOffset")}
    except Exception:
        return {"timezone": None, "offset": None}


if __name__ == "__main__":
    uvicorn.run("main:app", host="0.0.0.0", port=8003,
                workers=int(os.getenv("WORKERS", 2)), log_level="info")
