# 🛡 MetaIntel Platform — v2.0 (Images + Videos)

## What's New in v2

Full video forensics pipeline added alongside images. Every capability has a video-native equivalent.

| Capability | Images | Videos |
|---|---|---|
| Metadata extraction | ExifTool (EXIF/IPTC/XMP) | FFprobe + MediaInfo |
| GPS coordinates | EXIF GPS tags | QuickTime atoms / ISO 6709 |
| GPS movement track | — | Multi-point GPS stream |
| Anomaly detection | 9 checks | 8 checks (codec, encoder, HDR) |
| Error Level Analysis | Whole image | Per-frame (up to 120 frames) |
| Splice detection | — | Histogram discontinuity |
| Re-encoding fingerprint | — | DCT block variance |
| A/V sync analysis | — | Stream start-time delta |
| AI/Deepfake detection | Image-level | Per-frame face-region scoring |
| SDK | ✅ | ✅ unified `analyzeMedia()` |

## Supported Formats

**Images:** JPEG · PNG · TIFF · WebP · HEIC · DNG · CR2 · NEF · ARW · RAF (RAW)
**Videos:** MP4 · MOV · MKV · AVI · WebM · MPEG · 3GP · FLV · WMV · MXF · MPEG-TS

## Quick Start

```bash
git clone https://github.com/your-org/metaintel-platform && cd metaintel-platform
cp .env.example .env   # set DB_PASSWORD, REDIS_PASSWORD, MINIO keys
# Optional: edit .env to set APP_URL, MAPBOX_TOKEN, and any provider credentials
docker compose up -d --build
docker compose exec laravel-api php artisan key:generate
docker compose exec laravel-api php artisan migrate --seed
docker compose exec laravel-api php artisan metaintel:setup-elasticsearch
```

Open http://localhost

> Local development uses HTTP on port 80. The Docker stack no longer requires Nginx SSL certs for a local run.

## API — Upload any media

```http
POST /api/v1/media/upload
Content-Type: multipart/form-data

file: <binary>          # image or video
run_forensics: 1
run_ai: 1
max_frames: 48          # video: frames to extract
```

## SDK

```python
from metaintel import MetaIntelClient, analyze_media
client = MetaIntelClient(base_url="https://...", api_key="mi_...")

# Works for images AND videos
result = analyze_media(client, "clip.mp4")
print(result["forensics"]["deepfake_detected"])   # False
print(result["forensics"]["frames_analyzed"])     # 48
```

```javascript
import { MetaIntelClient, analyzeMedia } from '@metaintel/sdk';
const client = new MetaIntelClient({ baseUrl: '...', apiKey: 'mi_...' });

const result = await analyzeMedia(client, videoFile);
console.log(result.forensics.authenticity_verdict); // "authentic"
console.log(result.frames.length);                  // 48
```

## Migration from v1

The `/images` endpoint still works unchanged. The new `/media` endpoint handles both:

```bash
php artisan migrate   # adds media_type, video_metadata, video_frames tables
```

## Environment — v2 additions

```env
MAX_UPLOAD_SIZE_MB=500
VIDEO_MAX_FRAMES=48
FFPROBE_PATH=/usr/bin/ffprobe
FFMPEG_PATH=/usr/bin/ffmpeg
MEDIAINFO_PATH=/usr/bin/mediainfo
```

## Video Processing Pipeline

Upload → FFprobe analysis → QuickTime atom parsing → GPS extraction → Anomaly detection
→ Timeline events → Frame extraction (FFmpeg/OpenCV) → Per-frame ELA
→ Splice detection → Re-encoding fingerprint → A/V sync → Deepfake scoring
→ Elasticsearch indexing → Webhook dispatch

## Performance

| File | Avg time |
|---|---|
| JPEG image | 2–5 s |
| MP4 video < 1 min | 30–60 s |
| MP4 video 1–10 min | 60–180 s |
| 4K video | 3–8 min (GPU recommended) |
