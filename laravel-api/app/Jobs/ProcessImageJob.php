<?php

namespace App\Jobs;

use App\Models\{Image, MetadataRecord, GpsData, ForensicAnalysis, TimelineEvent};
use App\Services\{MetadataExtractionService, ForensicsService, GeospatialService, AnomalyDetectionService};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

class ProcessImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $timeout  = 300; // 5 minutes max
    public int    $tries    = 3;
    public int    $backoff  = 30;

    public function __construct(
        private readonly string $imageId,
        private readonly array  $options = []
    ) {}

    public function handle(
        MetadataExtractionService $metadataService,
        ForensicsService          $forensicsService,
        GeospatialService         $geospatialService,
        AnomalyDetectionService   $anomalyService
    ): void {
        $image = Image::findOrFail($this->imageId);
        $image->markAsProcessing();

        $startTime = microtime(true);

        Log::info("Processing image {$image->id}: {$image->original_filename}");

        try {
            DB::transaction(function () use ($image, $metadataService, $forensicsService, $geospatialService, $anomalyService) {

                // ── Step 1: Metadata Extraction ───────────────────────────────
                Log::debug("[{$image->id}] Step 1: Metadata extraction");
                $rawMetadata = $metadataService->extract($image);

                $metadata = MetadataRecord::updateOrCreate(
                    ['image_id' => $image->id],
                    $this->buildMetadataPayload($rawMetadata)
                );

                // ── Step 2: Anomaly Detection ─────────────────────────────────
                Log::debug("[{$image->id}] Step 2: Anomaly detection");
                $anomalies = $anomalyService->detect($rawMetadata, $metadata);
                $metadata->update([
                    'anomaly_flags' => $anomalies,
                    'anomaly_count' => count($anomalies),
                ]);

                // ── Step 3: GPS Extraction ────────────────────────────────────
                if (!empty($rawMetadata['GPS']) && !isset($this->options['skip_gps'])) {
                    Log::debug("[{$image->id}] Step 3: GPS extraction");
                    $gpsPayload = $geospatialService->processGps($image, $rawMetadata['GPS']);
                    if ($gpsPayload) {
                        GpsData::updateOrCreate(['image_id' => $image->id], $gpsPayload);
                    }
                }

                // ── Step 4: Timeline Events ───────────────────────────────────
                Log::debug("[{$image->id}] Step 4: Timeline events");
                $this->buildTimelineEvents($image, $metadata);

                // ── Step 5: Forensic Analysis ─────────────────────────────────
                $runForensics = $this->options['run_forensics'] ?? true;
                if ($runForensics && !($this->options['forensics_only'] ?? false) || ($this->options['forensics_only'] ?? false)) {
                    Log::debug("[{$image->id}] Step 5: Forensic analysis");
                    $forensicResult = $forensicsService->analyze($image, $this->options);

                    ForensicAnalysis::updateOrCreate(
                        ['image_id' => $image->id],
                        $forensicResult
                    );
                }

                // ── Step 6: Generate perceptual hash ──────────────────────────
                Log::debug("[{$image->id}] Step 6: Perceptual hash");
                $phash = $metadataService->computePerceptualHash($image);
                $image->update(['phash' => $phash]);

                // ── Step 7: Index in Elasticsearch ────────────────────────────
                Log::debug("[{$image->id}] Step 7: Elasticsearch indexing");
                IndexImageJob::dispatch($image->id)->onQueue('indexing');

                // ── Step 8: Fire webhooks ──────────────────────────────────────
                \App\Events\ImageProcessed::dispatch($image);
            });

            $elapsed = round((microtime(true) - $startTime) * 1000);
            Log::info("[{$image->id}] Processing completed in {$elapsed}ms");

            $image->markAsCompleted();

        } catch (Throwable $e) {
            Log::error("[{$image->id}] Processing failed: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            $image->markAsFailed();
            throw $e; // Allow retry mechanism
        }
    }

    private function buildMetadataPayload(array $raw): array
    {
        $exif    = $raw['EXIF']       ?? [];
        $iptc    = $raw['IPTC']       ?? [];
        $xmp     = $raw['XMP']        ?? [];
        $icc     = $raw['ICC_Profile'] ?? [];
        $maker   = $raw['MakerNotes'] ?? [];
        $file    = $raw['File']       ?? [];
        $comp    = $raw['Composite']  ?? [];

        return [
            // Dimensions
            'width'                => $exif['ExifImageWidth']  ?? $file['ImageWidth']  ?? null,
            'height'               => $exif['ExifImageHeight'] ?? $file['ImageHeight'] ?? null,
            'color_space'          => $exif['ColorSpace']      ?? $icc['ColorSpaceData'] ?? null,
            'bit_depth'            => $exif['BitsPerSample']   ?? null,

            // Camera
            'camera_make'          => $exif['Make']            ?? null,
            'camera_model'         => $exif['Model']           ?? null,
            'lens_model'           => $exif['LensModel']       ?? $exif['Lens'] ?? null,
            'software'             => $exif['Software']        ?? $xmp['CreatorTool'] ?? null,
            'firmware_version'     => $maker['FirmwareVersion']?? null,
            'serial_number'        => $exif['SerialNumber']    ?? $maker['SerialNumber'] ?? null,

            // Capture
            'exposure_time'        => $exif['ExposureTime']    ?? null,
            'f_number'             => $exif['FNumber']         ?? null,
            'iso_speed'            => $exif['ISO']             ?? $exif['ISOSpeedRatings'] ?? null,
            'focal_length'         => $exif['FocalLength']     ?? null,
            'focal_length_35mm'    => $comp['FocalLength35efl'] ?? $exif['FocalLengthIn35mmFilm'] ?? null,
            'flash'                => $exif['Flash']           ?? null,
            'white_balance'        => $exif['WhiteBalance']    ?? null,
            'metering_mode'        => $exif['MeteringMode']    ?? null,
            'exposure_mode'        => $exif['ExposureMode']    ?? null,
            'scene_capture_type'   => $exif['SceneCaptureType']?? null,

            // Timestamps
            'date_time_original'   => $this->parseDateTime($exif['DateTimeOriginal']  ?? null),
            'date_time_digitized'  => $this->parseDateTime($exif['DateTimeDigitized'] ?? null),
            'date_time_modified'   => $this->parseDateTime($exif['DateTime']          ?? null),
            'timezone_offset'      => $exif['OffsetTimeOriginal'] ?? $exif['OffsetTime'] ?? null,
            'sub_sec_time'         => $exif['SubSecTimeOriginal']  ?? null,

            // GPS summary
            'gps_latitude'         => $comp['GPSLatitude']  ?? null,
            'gps_longitude'        => $comp['GPSLongitude'] ?? null,
            'gps_altitude'         => $comp['GPSAltitude']  ?? null,

            // IPTC/XMP
            'copyright'            => $iptc['CopyrightNotice'] ?? $xmp['Rights'] ?? null,
            'creator'              => $iptc['By-line']         ?? $xmp['Creator'] ?? null,
            'credit'               => $iptc['Credit']          ?? null,
            'source'               => $iptc['Source']          ?? null,
            'caption'              => $iptc['Caption-Abstract']?? $xmp['Description'] ?? null,
            'headline'             => $iptc['Headline']        ?? $xmp['Headline'] ?? null,
            'keywords'             => $this->parseKeywords($iptc['Keywords'] ?? $xmp['Subject'] ?? null),
            'subject_code'         => $iptc['SubjectReference']?? null,

            // ICC
            'icc_profile_name'          => $icc['ProfileDescription'] ?? null,
            'color_profile_description' => $icc['ProfileDescription'] ?? null,
            'rendering_intent'          => $icc['MediaWhitePoint']    ?? null,

            // Flags
            'has_exif'       => !empty($exif),
            'has_iptc'       => !empty($iptc),
            'has_xmp'        => !empty($xmp),
            'has_icc'        => !empty($icc),
            'has_maker_notes'=> !empty($maker),

            // Raw dumps
            'raw_exif'       => $exif,
            'raw_iptc'       => $iptc,
            'raw_xmp'        => $xmp,
            'raw_maker_notes'=> $maker,
            'raw_icc'        => $icc,
        ];
    }

    private function buildTimelineEvents(Image $image, MetadataRecord $metadata): void
    {
        $events = [];

        if ($metadata->date_time_original) {
            $events[] = [
                'image_id'    => $image->id,
                'event_type'  => 'capture',
                'event_source'=> 'exif',
                'event_time'  => $metadata->date_time_original,
                'timezone'    => $metadata->timezone_offset,
                'description' => 'Image captured',
                'metadata'    => [
                    'camera' => trim(($metadata->camera_make ?? '') . ' ' . ($metadata->camera_model ?? '')),
                ],
            ];
        }

        if ($metadata->date_time_modified && $metadata->date_time_original &&
            $metadata->date_time_modified->gt($metadata->date_time_original)) {
            $events[] = [
                'image_id'    => $image->id,
                'event_type'  => 'edit',
                'event_source'=> 'exif',
                'event_time'  => $metadata->date_time_modified,
                'description' => 'Image modified',
                'metadata'    => ['software' => $metadata->software],
                'is_anomaly'  => false,
            ];
        }

        $events[] = [
            'image_id'    => $image->id,
            'event_type'  => 'upload',
            'event_source'=> 'system',
            'event_time'  => $image->created_at,
            'description' => 'Image uploaded to MetaIntel',
            'metadata'    => ['ip' => $image->upload_ip],
        ];

        foreach ($events as $event) {
            TimelineEvent::updateOrCreate(
                ['image_id' => $image->id, 'event_type' => $event['event_type'], 'event_source' => $event['event_source']],
                $event
            );
        }
    }

    private function parseDateTime(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $value)?->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseKeywords(mixed $value): ?array
    {
        if (!$value) return null;
        if (is_array($value)) return $value;
        return array_map('trim', explode(',', (string)$value));
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProcessImageJob permanently failed for image {$this->imageId}", [
            'error' => $exception->getMessage(),
        ]);

        Image::where('id', $this->imageId)->update(['status' => 'failed']);
    }
}
