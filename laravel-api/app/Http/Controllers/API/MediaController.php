<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Jobs\ProcessMediaJob;
use App\Models\{Image, VideoMetadata, VideoFrame, VideoForensicAnalysis, GpsData};
use App\Services\ImageStorageService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

class MediaController extends Controller
{
    public function __construct(private readonly ImageStorageService $storageService) {}

    // ─── Upload (image or video) ──────────────────────────────────────────────
    public function upload(UploadMediaRequest $request): JsonResponse
    {
        $file      = $request->getUploadedFile();
        $mimeType  = $file->getMimeType();
        $mediaType = $request->detectMediaType();

        // MIME validation against allowlist
        $allowed = array_merge(
            UploadMediaRequest::IMAGE_MIMES,
            UploadMediaRequest::VIDEO_MIMES
        );
        if (!in_array($mimeType, $allowed)) {
            return response()->json(['error' => 'Unsupported file type: ' . $mimeType], 422);
        }

        DB::beginTransaction();
        try {
            $sha256 = hash_file('sha256', $file->getRealPath());
            $md5    = hash_file('md5',    $file->getRealPath());

            // Deduplication
            $existing = Image::where('sha256_hash', $sha256)->first();
            if ($existing && $request->boolean('deduplicate', true)) {
                DB::rollBack();
                return response()->json([
                    'deduplicated' => true,
                    'media'        => $existing->load($this->eagerLoads($existing->media_type ?? 'image')),
                    'message'      => 'File already exists in the system',
                ], 200);
            }

            // Store file
            $storagePath = $this->storageService->storeMedia($file, $sha256, $mediaType);

            $media = Image::create([
                'media_type'        => $mediaType,
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename'   => basename($storagePath),
                'storage_path'      => $storagePath,
                'storage_disk'      => config('filesystems.default'),
                'mime_type'         => $mimeType,
                'file_size'         => $file->getSize(),
                'sha256_hash'       => $sha256,
                'md5_hash'          => $md5,
                'status'            => 'queued',
                'upload_source'     => $request->header('X-Upload-Source', 'web'),
                'upload_ip'         => $request->ip(),
                'upload_user_agent' => $request->userAgent(),
                'user_id'           => $request->user()?->id,
            ]);

            DB::commit();

            // Dispatch unified processing job
            ProcessMediaJob::dispatch($media->id, $request->only([
                'run_forensics', 'run_ai', 'run_geospatial', 'max_frames', 'ela_quality',
            ]))->onQueue('image-processing')->delay(now()->addSeconds(2));

            return response()->json([
                'media'   => $media,
                'type'    => $mediaType,
                'message' => ucfirst($mediaType) . ' queued for processing',
            ], 202);

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    // ─── Get single media item ────────────────────────────────────────────────
    public function show(string $id): JsonResponse
    {
        $media     = Image::findOrFail($id);
        $mediaType = $media->media_type ?? 'image';

        $media->load($this->eagerLoads($mediaType));

        if ($mediaType === 'video') {
            return response()->json([
                'media'         => $media,
                'type'          => 'video',
                'metadata_tree' => $media->videoMetadata?->toMetadataTree(),
                'geojson'       => $media->gpsData?->toGeoJSON(),
                'frames'        => $media->videoFrames?->map(fn($f) => [
                    'id'               => $f->id,
                    'frame_number'     => $f->frame_number,
                    'timestamp'        => $f->formatted_timestamp,
                    'timestamp_seconds'=> $f->timestamp_seconds,
                    'frame_type'       => $f->frame_type,
                    'thumbnail_url'    => $f->thumbnail_url,
                    'ela_score'        => $f->ela_score,
                    'is_suspicious'    => $f->is_suspicious,
                    'face_count'       => $f->face_count,
                ])->values(),
                'forensics'     => $media->videoForensicAnalysis,
                'summary'       => $this->buildVideoSummary($media),
            ]);
        }

        // Image
        return response()->json([
            'media'         => $media,
            'image'         => $media,     // backwards compat
            'type'          => 'image',
            'metadata_tree' => $media->metadata?->toMetadataTree(),
            'geojson'       => $media->gpsData?->toGeoJSON(),
            'summary'       => $media->getAnalysisSummary(),
        ]);
    }

    // ─── List all media ───────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Image::with([
            'metadata:image_id,camera_make,camera_model,anomaly_count',
            'videoMetadata:media_id,video_codec,duration_seconds,width,height',
            'gpsData:image_id,latitude,longitude,city,country',
        ])
        ->select(['id', 'media_type', 'original_filename', 'mime_type',
                  'file_size', 'status', 'created_at']);

        if ($request->filled('type'))   $query->where('media_type', $request->type);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->boolean('with_gps')) $query->withGps();

        return response()->json(
            $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20))
        );
    }

    // ─── Status polling ───────────────────────────────────────────────────────
    public function status(string $id): JsonResponse
    {
        $media = Image::select(['id', 'media_type', 'status', 'updated_at'])->findOrFail($id);
        return response()->json(['id' => $media->id, 'type' => $media->media_type,
                                  'status' => $media->status, 'updated_at' => $media->updated_at]);
    }

    // ─── Delete media ──────────────────────────────────────────────────────────
    public function destroy(string $id): JsonResponse
    {
        $media = Image::findOrFail($id);
        $this->storageService->delete($media->storage_path, $media->storage_disk);
        $media->delete();
        return response()->json(['message' => ucfirst($media->media_type ?? 'Media') . ' deleted']);
    }

    // ─── Get video frames ──────────────────────────────────────────────────────
    public function videoFrames(string $id, Request $request): JsonResponse
    {
        $media = Image::where('media_type', 'video')->findOrFail($id);

        $frames = VideoFrame::where('media_id', $id)
            ->when($request->boolean('suspicious_only'),
                   fn($q) => $q->where('is_suspicious', true))
            ->orderBy('timestamp_seconds')
            ->get();

        return response()->json(['frames' => $frames]);
    }

    // ─── Get GPS track for video ──────────────────────────────────────────────
    public function videoGpsTrack(string $id): JsonResponse
    {
        $meta = VideoMetadata::where('media_id', $id)->firstOrFail();

        if (empty($meta->gps_track)) {
            return response()->json(['has_track' => false, 'track' => []]);
        }

        return response()->json([
            'has_track' => true,
            'track'     => $meta->gps_track,
            'geojson'   => [
                'type'     => 'FeatureCollection',
                'features' => [[
                    'type'       => 'Feature',
                    'geometry'   => [
                        'type'        => 'LineString',
                        'coordinates' => array_map(
                            fn($pt) => [$pt['lon'], $pt['lat'], $pt['alt'] ?? 0],
                            $meta->gps_track
                        ),
                    ],
                    'properties' => ['media_id' => $id, 'type' => 'gps_track'],
                ]],
            ],
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────
    private function eagerLoads(string $mediaType): array
    {
        if ($mediaType === 'video') {
            return ['videoMetadata', 'videoForensicAnalysis', 'videoFrames', 'gpsData', 'timelineEvents'];
        }
        return ['metadata', 'forensicAnalysis', 'gpsData', 'timelineEvents'];
    }

    private function buildVideoSummary(Image $media): array
    {
        $vm  = $media->videoMetadata;
        $vfa = $media->videoForensicAnalysis;

        return [
            'id'                  => $media->id,
            'filename'            => $media->original_filename,
            'type'                => 'video',
            'size'                => $media->formatted_size,
            'status'              => $media->status,
            'duration'            => $vm?->formatted_duration,
            'resolution'          => $vm?->resolution,
            'codec'               => $vm?->video_codec ? strtoupper($vm->video_codec) : null,
            'has_gps'             => $vm?->has_gps ?? false,
            'has_gps_track'       => $vm?->has_gps_track ?? false,
            'anomaly_count'       => $vm?->anomaly_count ?? 0,
            'authenticity'        => $vfa?->authenticity_verdict ?? 'unknown',
            'deepfake_detected'   => $vfa?->deepfake_detected,
            'suspicious_frames'   => $vfa?->suspicious_frame_count ?? 0,
            'frames_analyzed'     => $vfa?->frames_analyzed ?? 0,
            'created_at'          => $media->created_at->toIso8601String(),
        ];
    }
}
