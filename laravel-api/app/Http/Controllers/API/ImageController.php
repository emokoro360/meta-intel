<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadImageRequest;
use App\Http\Requests\BatchUploadRequest;
use App\Jobs\ProcessImageJob;
use App\Jobs\ProcessBatchJob;
use App\Models\Image;
use App\Models\BatchJob;
use App\Models\GpsData;
use App\Services\ImageStorageService;
use App\Services\MetadataSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    public function __construct(
        private readonly ImageStorageService  $storageService,
        private readonly MetadataSearchService $searchService
    ) {}

    // ─── Upload single image ──────────────────────────────────────────────────
    public function upload(UploadImageRequest $request): JsonResponse
    {
        $file = $request->file('image');

        // Security: verify mime type independently of extension
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, config('metaintel.allowed_mime_types'))) {
            return response()->json(['error' => 'Unsupported file type'], 422);
        }

        DB::beginTransaction();
        try {
            $sha256 = hash_file('sha256', $file->getRealPath());
            $md5    = hash_file('md5',    $file->getRealPath());

            // Deduplicate
            $existing = Image::where('sha256_hash', $sha256)->first();
            if ($existing && $request->boolean('deduplicate', true)) {
                DB::rollBack();
                return response()->json([
                    'deduplicated' => true,
                    'image'        => $existing->load(['metadata', 'gpsData', 'forensicAnalysis']),
                    'message'      => 'Image already exists in the system',
                ], 200);
            }

            $storagePath = $this->storageService->store($file, $sha256);

            $image = Image::create([
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

            // Dispatch processing job
            $options = $request->only(['run_forensics', 'run_ai', 'run_geospatial']);
            ProcessImageJob::dispatch($image->id, $options)
                ->onQueue('image-processing')
                ->delay(now()->addSeconds(2));

            return response()->json([
                'image'   => $image,
                'message' => 'Image queued for processing',
            ], 202);

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    // ─── Batch upload ─────────────────────────────────────────────────────────
    public function batchUpload(BatchUploadRequest $request): JsonResponse
    {
        $files = $request->file('images');
        $batchName = $request->input('batch_name', 'Batch ' . now()->format('Y-m-d H:i'));

        $batch = BatchJob::create([
            'name'         => $batchName,
            'uuid'         => Str::uuid(),
            'status'       => 'pending',
            'total_images' => count($files),
            'options'      => $request->except(['images', 'batch_name']),
            'user_id'      => $request->user()?->id,
        ]);

        $imageIds = [];
        foreach ($files as $file) {
            try {
                $sha256      = hash_file('sha256', $file->getRealPath());
                $storagePath = $this->storageService->store($file, $sha256);

                $image = Image::create([
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_filename'   => basename($storagePath),
                    'storage_path'      => $storagePath,
                    'storage_disk'      => config('filesystems.default'),
                    'mime_type'         => $file->getMimeType(),
                    'file_size'         => $file->getSize(),
                    'sha256_hash'       => $sha256,
                    'md5_hash'          => hash_file('md5', $file->getRealPath()),
                    'status'            => 'queued',
                    'batch_id'          => $batch->id,
                    'user_id'           => $request->user()?->id,
                ]);

                $imageIds[] = $image->id;
            } catch (\Throwable $e) {
                $batch->increment('failed_images');
                report($e);
            }
        }

        ProcessBatchJob::dispatch($batch->id, $imageIds)->onQueue('batch-processing');

        return response()->json([
            'batch'     => $batch,
            'image_ids' => $imageIds,
            'message'   => "Batch of {$batch->total_images} images queued",
        ], 202);
    }

    // ─── Get single image with all analysis ──────────────────────────────────
    public function show(string $id): JsonResponse
    {
        $image = Image::with([
            'metadata',
            'gpsData',
            'forensicAnalysis',
            'timelineEvents',
        ])->findOrFail($id);

        return response()->json([
            'image'       => $image,
            'metadata_tree' => $image->metadata?->toMetadataTree(),
            'geojson'     => $image->gpsData?->toGeoJSON(),
            'summary'     => $image->getAnalysisSummary(),
        ]);
    }

    // ─── Get analysis status (polling) ───────────────────────────────────────
    public function status(string $id): JsonResponse
    {
        $image = Image::select(['id', 'status', 'updated_at'])->findOrFail($id);
        return response()->json([
            'id'         => $image->id,
            'status'     => $image->status,
            'updated_at' => $image->updated_at,
        ]);
    }

    // ─── List images with filters ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Image::with(['metadata:image_id,camera_make,camera_model,anomaly_count', 'gpsData:image_id,latitude,longitude,city,country'])
            ->select(['id', 'original_filename', 'mime_type', 'file_size', 'status', 'created_at']);

        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('mime_type')) $query->byMimeType($request->mime_type);
        if ($request->boolean('with_gps')) $query->withGps();
        if ($request->filled('batch_id'))  $query->forBatch($request->batch_id);
        if ($request->filled('search'))    $query->whereIn('id', $this->searchService->search($request->search));

        $images = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return response()->json($images);
    }

    // ─── Get all GPS points as GeoJSON FeatureCollection ─────────────────────
    public function geoJSON(Request $request): JsonResponse
    {
        $query = GpsData::with(['image:id,original_filename,status,created_at'])
            ->whereHas('image', fn($q) => $q->completed());

        if ($request->filled('batch_id')) {
            $query->whereHas('image', fn($q) => $q->forBatch($request->batch_id));
        }

        $features = $query->get()->map(fn($gps) => $gps->toGeoJSON());

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    // ─── Sanitize image (strip metadata) ─────────────────────────────────────
    public function sanitize(string $id): JsonResponse
    {
        $image = Image::findOrFail($id);

        if (!$image->isCompleted()) {
            return response()->json(['error' => 'Image must be fully processed before sanitization'], 422);
        }

        \App\Jobs\SanitizeImageJob::dispatch($image->id)->onQueue('sanitization');

        return response()->json(['message' => 'Sanitization queued', 'image_id' => $id]);
    }

    // ─── Delete image ─────────────────────────────────────────────────────────
    public function destroy(string $id): JsonResponse
    {
        $image = Image::findOrFail($id);
        $this->storageService->delete($image->storage_path, $image->storage_disk);
        $image->delete();

        return response()->json(['message' => 'Image deleted']);
    }

    // ─── Reprocess image ─────────────────────────────────────────────────────
    public function reprocess(string $id, Request $request): JsonResponse
    {
        $image = Image::findOrFail($id);
        $image->update(['status' => 'queued']);

        ProcessImageJob::dispatch($image->id, $request->only(['run_forensics', 'run_ai', 'run_geospatial']))
            ->onQueue('image-processing');

        return response()->json(['message' => 'Image requeued for processing']);
    }
}
