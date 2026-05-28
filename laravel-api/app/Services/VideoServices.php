<?php

namespace App\Services;

use App\Models\{Image, VideoMetadata};
use Illuminate\Support\Facades\{Http, Log, Storage};

// ─────────────────────────────────────────────────────────────────────────────
// VideoMetadataService  –  calls Python metadata microservice for video
// ─────────────────────────────────────────────────────────────────────────────
class VideoMetadataService
{
    private string $serviceUrl;

    public function __construct()
    {
        $this->serviceUrl = config('services.metadata.url', 'http://metadata-service:8001');
    }

    /**
     * Extract all metadata from a video via the Python microservice (FFprobe).
     * Returns array with 'metadata' key ready for VideoMetadata::create().
     */
    public function extract(Image $media): array
    {
        $imagePath = $this->resolveMediaPath($media);

        try {
            $response = Http::timeout(120)
                ->post("{$this->serviceUrl}/extract-video", [
                    'media_path' => $imagePath,
                    'media_id'   => $media->id,
                    'options'    => [
                        'extract_frames'      => false,   // frames handled by forensics service
                        'extract_gps_track'   => true,
                        'extract_chapters'    => true,
                        'extract_subtitles'   => true,
                        'detect_hdr'          => true,
                    ],
                ]);

            if ($response->failed()) {
                Log::error("VideoMetadataService error for {$media->id}: " . $response->body());
                throw new \RuntimeException("Video metadata service returned: " . $response->status());
            }

            return $response->json();

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning("Video metadata service unreachable for {$media->id}: " . $e->getMessage());
            return ['metadata' => [], 'software_fingerprint' => []];
        }
    }

    private function resolveMediaPath(Image $media): string
    {
        if ($media->storage_disk === 'local') {
            return Storage::path($media->storage_path);
        }
        return Storage::disk($media->storage_disk)
            ->temporaryUrl($media->storage_path, now()->addMinutes(30));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// VideoForensicsService  –  calls Python forensics microservice for video
// ─────────────────────────────────────────────────────────────────────────────
class VideoForensicsService
{
    private string $serviceUrl;

    public function __construct()
    {
        $this->serviceUrl = config('services.forensics.url', 'http://forensics-service:8002');
    }

    /**
     * Run full video forensics pipeline.
     * Returns array with 'frames' and 'analysis' keys.
     */
    public function analyze(Image $media, VideoMetadata $metadata, array $options = []): array
    {
        $mediaPath = $this->resolveMediaPath($media);

        // Calculate smart sampling rate based on duration
        $duration  = $metadata->duration_seconds ?? 60;
        $maxFrames = min((int) ($duration / 2), 60);   // 1 frame per 2s, max 60

        try {
            $response = Http::timeout(300)   // video analysis can take a while
                ->post("{$this->serviceUrl}/analyze-video", [
                    'media_path'          => $mediaPath,
                    'media_id'            => $media->id,
                    'duration_seconds'    => $duration,
                    'max_frames'          => $max_frames ?? $maxFrames,
                    'run_ela'             => $options['run_ela']         ?? true,
                    'run_noise'           => $options['run_noise']       ?? true,
                    'run_deepfake'        => $options['run_ai']          ?? true,
                    'run_splice_detect'   => $options['run_forensics']   ?? true,
                    'run_reencoding'      => $options['run_forensics']   ?? true,
                    'run_av_sync'         => true,
                    'frame_storage_prefix'=> "frames/{$media->id}/",
                ]);

            if ($response->failed()) {
                Log::error("VideoForensicsService error for {$media->id}: " . $response->body());
                return $this->buildFailedPayload();
            }

            $data     = $response->json();
            $frames   = $this->processFrameResults($media, $data['frames'] ?? []);
            $analysis = $this->mapAnalysisPayload($data);

            return ['frames' => $frames, 'analysis' => $analysis];

        } catch (\Throwable $e) {
            Log::error("VideoForensicsService failed for {$media->id}: " . $e->getMessage());
            return $this->buildFailedPayload();
        }
    }

    private function processFrameResults(Image $media, array $frames): array
    {
        return array_map(function (array $frame) use ($media) {
            // Store ELA frame image to S3 if present
            $storagePath = null;
            if (!empty($frame['thumbnail_b64'])) {
                $storagePath = "frames/{$media->id}/frame_{$frame['frame_number']}.jpg";
                Storage::put($storagePath, base64_decode($frame['thumbnail_b64']));
                unset($frame['thumbnail_b64']);
            }

            return [
                'media_id'          => $media->id,
                'timestamp_seconds' => $frame['timestamp_seconds'],
                'frame_number'      => $frame['frame_number'],
                'storage_path'      => $storagePath,
                'frame_type'        => $frame['frame_type'] ?? 'keyframe',
                'ela_score'         => $frame['ela_score']   ?? null,
                'noise_score'       => $frame['noise_score'] ?? null,
                'is_suspicious'     => ($frame['ela_score'] ?? 0) > 35 || ($frame['noise_score'] ?? 0) > 40,
                'forensic_details'  => $frame['forensic_details'] ?? null,
                'faces_detected'    => $frame['faces']       ?? [],
                'face_count'        => count($frame['faces'] ?? []),
                'objects_detected'  => $frame['objects']     ?? [],
            ];
        }, $frames);
    }

    private function mapAnalysisPayload(array $data): array
    {
        return [
            'reencoding_detected'        => $data['reencoding_detected']        ?? null,
            'reencoding_confidence'      => $data['reencoding_confidence']      ?? null,
            'reencoding_evidence'        => $data['reencoding_evidence']        ?? [],
            'temporal_splicing_detected' => $data['splicing_detected']          ?? null,
            'splicing_confidence'        => $data['splicing_confidence']        ?? null,
            'splice_points'              => $data['splice_points']              ?? [],
            'av_sync_anomaly'            => $data['av_sync_anomaly']            ?? null,
            'av_sync_offset_ms'          => $data['av_sync_offset_ms']          ?? null,
            'deepfake_detected'          => $data['deepfake_detected']          ?? null,
            'deepfake_confidence'        => $data['deepfake_confidence']        ?? null,
            'deepfake_model_used'        => $data['deepfake_model_used']        ?? null,
            'deepfake_frame_scores'      => $data['deepfake_frame_scores']      ?? [],
            'avg_ela_score'              => $data['avg_ela_score']              ?? null,
            'max_ela_score'              => $data['max_ela_score']              ?? null,
            'suspicious_frame_count'     => $data['suspicious_frame_count']     ?? 0,
            'codec_mismatch_detected'    => $data['codec_mismatch_detected']    ?? null,
            'codec_anomalies'            => $data['codec_anomalies']            ?? [],
            'authenticity_verdict'       => $data['authenticity_verdict']       ?? 'unknown',
            'authenticity_score'         => $data['authenticity_score']         ?? null,
            'tampering_indicators'       => $data['tampering_indicators']       ?? [],
            'frames_analyzed'            => $data['frames_analyzed']            ?? 0,
            'analysis_version'           => $data['analysis_version']           ?? '1.0',
            'analyzed_at'                => now(),
            'processing_time_ms'         => $data['processing_time_ms']         ?? null,
        ];
    }

    private function resolveMediaPath(Image $media): string
    {
        if ($media->storage_disk === 'local') {
            return Storage::path($media->storage_path);
        }
        return Storage::disk($media->storage_disk)
            ->temporaryUrl($media->storage_path, now()->addMinutes(30));
    }

    private function buildFailedPayload(): array
    {
        return [
            'frames'   => [],
            'analysis' => [
                'authenticity_verdict' => 'unknown',
                'analysis_version'     => '1.0',
                'analyzed_at'          => now(),
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// VideoAnomalyService  –  detects metadata anomalies in video files
// ─────────────────────────────────────────────────────────────────────────────
class VideoAnomalyService
{
    public function detect(array $videoData): array
    {
        $issues   = [];
        $metadata = $videoData['metadata'] ?? [];
        $fp       = $videoData['software_fingerprint'] ?? [];

        // ── 1. Encoder fingerprint mismatch ──────────────────────────────────
        if (!empty($fp['detections'])) {
            foreach ($fp['detections'] as $detection) {
                $issues[] = [
                    'type'        => 'encoding_software_detected',
                    'severity'    => $detection['risk_level'] ?? 'low',
                    'description' => "Video encoded with {$detection['software']}",
                    'fields'      => ['encoder', 'software'],
                    'value'       => $detection['software'],
                ];
            }
        }

        // ── 2. Creation time vs file modification time inconsistency ─────────
        $creationTime  = $metadata['creation_time'] ?? null;
        $fileModTime   = $metadata['raw_container_tags']['date_time'] ?? null;
        if ($creationTime && $fileModTime) {
            try {
                $ct = \Carbon\Carbon::parse($creationTime);
                $ft = \Carbon\Carbon::parse($fileModTime);
                if ($ft->lt($ct)) {
                    $issues[] = [
                        'type'        => 'timestamp_regression',
                        'severity'    => 'high',
                        'description' => 'File modification time precedes video creation time',
                        'fields'      => ['creation_time', 'file_date'],
                    ];
                }
            } catch (\Throwable) {}
        }

        // ── 3. Missing device metadata on mobile codecs ───────────────────────
        $codec = $metadata['video_codec'] ?? '';
        if (in_array(strtolower($codec), ['h264', 'hevc', 'h265']) && empty($metadata['make'])) {
            $issues[] = [
                'type'        => 'missing_device_metadata',
                'severity'    => 'low',
                'description' => 'Mobile-style codec detected but device make/model is absent',
                'fields'      => ['make', 'model'],
            ];
        }

        // ── 4. GPS present – privacy risk ─────────────────────────────────────
        if (!empty($metadata['gps_latitude'])) {
            $issues[] = [
                'type'        => 'gps_coordinates_present',
                'severity'    => 'medium',
                'description' => 'GPS location data embedded in video atoms',
                'fields'      => ['gps_latitude', 'gps_longitude'],
            ];
        }

        // ── 5. GPS track present – full movement path ─────────────────────────
        if (!empty($metadata['gps_track']) && count($metadata['gps_track']) > 1) {
            $issues[] = [
                'type'        => 'gps_track_present',
                'severity'    => 'medium',
                'description' => count($metadata['gps_track']) . ' GPS track points embedded – full movement path exposed',
                'fields'      => ['gps_track'],
            ];
        }

        // ── 6. HDR metadata without matching codec ─────────────────────────────
        $isHdr         = $metadata['is_hdr'] ?? false;
        $colorTransfer = strtolower($metadata['color_transfer'] ?? '');
        if ($isHdr && !in_array($colorTransfer, ['smpte2084', 'arib-std-b67', 'bt2020-10', 'bt2020-12'])) {
            $issues[] = [
                'type'        => 'hdr_metadata_inconsistency',
                'severity'    => 'low',
                'description' => 'HDR flag set but color_transfer does not match HDR standard',
                'fields'      => ['is_hdr', 'color_transfer'],
            ];
        }

        // ── 7. Unusual or suspicious rotation ─────────────────────────────────
        $rotation = (int)($metadata['rotation'] ?? 0);
        if (!in_array($rotation, [0, 90, 180, 270])) {
            $issues[] = [
                'type'        => 'unusual_rotation',
                'severity'    => 'low',
                'description' => "Non-standard rotation value: {$rotation}°",
                'fields'      => ['rotation'],
                'value'       => $rotation,
            ];
        }

        // ── 8. Audio/Video codec mismatch for container ───────────────────────
        $container    = strtolower($metadata['container_format'] ?? '');
        $videoCodec   = strtolower($metadata['video_codec']     ?? '');
        $incompatible = [
            'flv'  => ['hevc', 'av1', 'vp9'],
            'avi'  => ['hevc', 'av1'],
        ];
        if (isset($incompatible[$container]) && in_array($videoCodec, $incompatible[$container])) {
            $issues[] = [
                'type'        => 'codec_container_mismatch',
                'severity'    => 'medium',
                'description' => strtoupper($videoCodec) . ' codec is unusual for ' . strtoupper($container) . ' container',
                'fields'      => ['video_codec', 'container_format'],
            ];
        }

        return $issues;
    }
}
