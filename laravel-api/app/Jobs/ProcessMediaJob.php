<?php

namespace App\Jobs;

use App\Models\{Image, VideoMetadata, VideoFrame, VideoForensicAnalysis};
use App\Services\{
    MetadataExtractionService,
    VideoMetadataService,
    ForensicsService,
    VideoForensicsService,
    GeospatialService,
    AnomalyDetectionService,
    VideoAnomalyService,
};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

class ProcessMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;   // 10 min – videos are slower
    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly string $mediaId,
        private readonly array  $options = []
    ) {}

    public function handle(
        MetadataExtractionService $imageMetadataService,
        VideoMetadataService      $videoMetadataService,
        ForensicsService          $forensicsService,
        VideoForensicsService     $videoForensicsService,
        GeospatialService         $geospatialService,
        AnomalyDetectionService   $imageAnomalyService,
        VideoAnomalyService       $videoAnomalyService
    ): void {
        $media = Image::findOrFail($this->mediaId);
        $media->markAsProcessing();

        $start = microtime(true);
        Log::info("ProcessMediaJob [{$media->media_type}] {$media->id}: {$media->original_filename}");

        try {
            DB::transaction(function () use (
                $media, $imageMetadataService, $videoMetadataService,
                $forensicsService, $videoForensicsService,
                $geospatialService, $imageAnomalyService, $videoAnomalyService
            ) {
                if ($media->media_type === 'video') {
                    $this->processVideo(
                        $media, $videoMetadataService, $videoForensicsService,
                        $geospatialService, $videoAnomalyService
                    );
                } else {
                    $this->processImage(
                        $media, $imageMetadataService, $forensicsService,
                        $geospatialService, $imageAnomalyService
                    );
                }

                // Common: compute perceptual hash, index in ES, dispatch webhooks
                $phash = $imageMetadataService->computePerceptualHash($media);
                $media->update(['phash' => $phash]);

                IndexImageJob::dispatch($media->id)->onQueue('indexing');
                \App\Events\ImageProcessed::dispatch($media);
            });

            $elapsed = round((microtime(true) - $start) * 1000);
            Log::info("ProcessMediaJob completed {$media->id} in {$elapsed}ms");
            $media->markAsCompleted();

        } catch (Throwable $e) {
            Log::error("ProcessMediaJob failed {$media->id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            $media->markAsFailed();
            throw $e;
        }
    }

    // ─── Image Pipeline ───────────────────────────────────────────────────────
    private function processImage(
        Image $media,
        MetadataExtractionService $metadataService,
        ForensicsService $forensicsService,
        GeospatialService $geospatialService,
        AnomalyDetectionService $anomalyService
    ): void {
        // 1. Extract metadata
        $raw      = $metadataService->extract($media);
        $payload  = $this->buildImageMetadataPayload($raw);
        $metadata = \App\Models\MetadataRecord::updateOrCreate(['image_id' => $media->id], $payload);

        // 2. Anomalies
        $anomalies = $anomalyService->detect($raw, $metadata);
        $metadata->update(['anomaly_flags' => $anomalies, 'anomaly_count' => count($anomalies)]);

        // 3. GPS
        if (!empty($raw['GPS'])) {
            $gpsPayload = $geospatialService->processGps($media, $raw['GPS']);
            if ($gpsPayload) {
                \App\Models\GpsData::updateOrCreate(['image_id' => $media->id], $gpsPayload);
            }
        }

        // 4. Timeline
        $this->buildImageTimeline($media, $metadata);

        // 5. Forensics
        if ($this->options['run_forensics'] ?? true) {
            $forensicResult = $forensicsService->analyze($media, $this->options);
            \App\Models\ForensicAnalysis::updateOrCreate(['image_id' => $media->id], $forensicResult);
        }
    }

    // ─── Video Pipeline ───────────────────────────────────────────────────────
    private function processVideo(
        Image $media,
        VideoMetadataService $videoMetadataService,
        VideoForensicsService $videoForensicsService,
        GeospatialService $geospatialService,
        VideoAnomalyService $videoAnomalyService
    ): void {
        // 1. Extract video metadata via FFprobe
        Log::debug("[{$media->id}] Video Step 1: FFprobe metadata extraction");
        $videoData = $videoMetadataService->extract($media);

        $metadata = VideoMetadata::updateOrCreate(
            ['media_id' => $media->id],
            $videoData['metadata']
        );

        // 2. Anomaly detection (codec, timestamp, encoder fingerprint)
        Log::debug("[{$media->id}] Video Step 2: Anomaly detection");
        $anomalies = $videoAnomalyService->detect($videoData);
        $metadata->update(['anomaly_flags' => $anomalies, 'anomaly_count' => count($anomalies)]);

        // 3. GPS extraction (from video atoms / QuickTime metadata)
        Log::debug("[{$media->id}] Video Step 3: GPS extraction");
        if ($metadata->gps_latitude && $metadata->gps_longitude) {
            $gpsPayload = $geospatialService->processGps($media, [
                'GPS:GPSLatitude'     => $metadata->gps_latitude,
                'GPS:GPSLongitude'    => $metadata->gps_longitude,
                'GPS:GPSAltitude'     => $metadata->gps_altitude,
            ]);
            if ($gpsPayload) {
                \App\Models\GpsData::updateOrCreate(['image_id' => $media->id], $gpsPayload);
            }
        }

        // 4. Timeline events from video timestamps
        Log::debug("[{$media->id}] Video Step 4: Timeline events");
        $this->buildVideoTimeline($media, $metadata);

        // 5. Frame extraction and forensics
        if ($this->options['run_forensics'] ?? true) {
            Log::debug("[{$media->id}] Video Step 5: Frame forensics");
            $forensicResult = $videoForensicsService->analyze($media, $metadata, $this->options);

            // Store extracted frames
            foreach ($forensicResult['frames'] ?? [] as $frameData) {
                VideoFrame::updateOrCreate(
                    ['media_id' => $media->id, 'frame_number' => $frameData['frame_number']],
                    $frameData
                );
            }

            // Store overall forensic verdict
            VideoForensicAnalysis::updateOrCreate(
                ['media_id' => $media->id],
                $forensicResult['analysis']
            );
        }
    }

    // ─── Image metadata payload builder (reuse from ProcessImageJob) ──────────
    private function buildImageMetadataPayload(array $raw): array
    {
        // Delegate to the same logic as ProcessImageJob
        return (new ProcessImageJob($this->mediaId))->buildMetadataPayloadPublic($raw);
    }

    private function buildImageTimeline(Image $media, \App\Models\MetadataRecord $metadata): void
    {
        if ($metadata->date_time_original) {
            \App\Models\TimelineEvent::updateOrCreate(
                ['image_id' => $media->id, 'event_type' => 'capture', 'event_source' => 'exif'],
                [
                    'event_time'  => $metadata->date_time_original,
                    'description' => 'Image captured on ' . trim(($metadata->camera_make ?? '') . ' ' . ($metadata->camera_model ?? '')),
                ]
            );
        }
        \App\Models\TimelineEvent::updateOrCreate(
            ['image_id' => $media->id, 'event_type' => 'upload', 'event_source' => 'system'],
            ['event_time' => $media->created_at, 'description' => 'Media uploaded to MetaIntel']
        );
    }

    private function buildVideoTimeline(Image $media, VideoMetadata $metadata): void
    {
        $events = [];

        if ($metadata->creation_time) {
            $events[] = [
                'image_id'    => $media->id,
                'event_type'  => 'capture',
                'event_source'=> 'ffprobe',
                'event_time'  => $metadata->creation_time,
                'timezone'    => $metadata->creation_timezone,
                'description' => 'Video recorded on ' . trim(($metadata->make ?? '') . ' ' . ($metadata->model ?? 'Unknown device')),
                'metadata'    => [
                    'duration'  => $metadata->formatted_duration,
                    'codec'     => $metadata->video_codec,
                    'resolution'=> $metadata->resolution,
                ],
            ];
        }

        if ($metadata->encoder) {
            $events[] = [
                'image_id'    => $media->id,
                'event_type'  => 'edit',
                'event_source'=> 'ffprobe',
                'event_time'  => $metadata->creation_time ?? $media->created_at,
                'description' => "Encoded with {$metadata->encoder}",
                'metadata'    => ['encoder' => $metadata->encoder],
            ];
        }

        $events[] = [
            'image_id'    => $media->id,
            'event_type'  => 'upload',
            'event_source'=> 'system',
            'event_time'  => $media->created_at,
            'description' => 'Video uploaded to MetaIntel',
            'metadata'    => ['ip' => $media->upload_ip],
        ];

        foreach ($events as $event) {
            \App\Models\TimelineEvent::updateOrCreate(
                ['image_id' => $media->id, 'event_type' => $event['event_type'], 'event_source' => $event['event_source']],
                $event
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        Image::where('id', $this->mediaId)->update(['status' => 'failed']);
        Log::error("ProcessMediaJob permanently failed {$this->mediaId}: " . $exception->getMessage());
    }
}
