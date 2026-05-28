<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Facades\{Http, Log};

// ─────────────────────────────────────────────────────────────────────────────
// GeospatialService  –  calls the Python geospatial microservice
// ─────────────────────────────────────────────────────────────────────────────
class GeospatialService
{
    private string $serviceUrl;

    public function __construct()
    {
        $this->serviceUrl = config('services.geospatial.url',
            env('GEOSPATIAL_SERVICE_URL', 'http://geospatial-service:8003'));
    }

    /**
     * Process GPS tags from raw metadata and return a GPS record payload.
     */
    public function processGps(Image $image, array $rawGps): ?array
    {
        if (empty($rawGps)) return null;

        try {
            $response = Http::timeout(20)
                ->post("{$this->serviceUrl}/process-gps", [
                    'image_id' => $image->id,
                    'gps_data' => $rawGps,
                    'timestamp'=> null,
                ]);

            if ($response->failed() || !$response->json('has_gps')) {
                return null;
            }

            $data = $response->json();
            unset($data['image_id'], $data['has_gps']);

            return $data;

        } catch (\Throwable $e) {
            Log::warning("GeospatialService failed for {$image->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Reverse geocode a lat/lon coordinate.
     */
    public function reverseGeocode(float $lat, float $lon): array
    {
        try {
            $response = Http::timeout(15)
                ->post("{$this->serviceUrl}/reverse-geocode", [
                    'lat' => $lat,
                    'lon' => $lon,
                ]);

            return $response->successful() ? $response->json() : [];
        } catch (\Throwable $e) {
            Log::warning("Reverse geocode failed ({$lat},{$lon}): " . $e->getMessage());
            return [];
        }
    }

    /**
     * Analyze movement pattern across multiple GPS waypoints.
     */
    public function analyzeMovement(array $waypoints): array
    {
        try {
            $response = Http::timeout(30)
                ->post("{$this->serviceUrl}/analyze-movement", [
                    'waypoints' => $waypoints,
                ]);

            return $response->successful() ? $response->json() : ['segments' => [], 'anomalies' => []];
        } catch (\Throwable $e) {
            Log::warning("Movement analysis failed: " . $e->getMessage());
            return ['segments' => [], 'anomalies' => []];
        }
    }

    /**
     * Build a GeoJSON FeatureCollection from a list of image records.
     */
    public function buildGeoJSON(array $images): array
    {
        try {
            $response = Http::timeout(20)
                ->post("{$this->serviceUrl}/geojson", ['images' => $images]);

            return $response->successful()
                ? $response->json()
                : ['type' => 'FeatureCollection', 'features' => []];
        } catch (\Throwable $e) {
            Log::warning("GeoJSON build failed: " . $e->getMessage());
            return ['type' => 'FeatureCollection', 'features' => []];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ForensicsService  –  calls the Python forensics microservice
// ─────────────────────────────────────────────────────────────────────────────
class ForensicsService
{
    private string $serviceUrl;

    public function __construct()
    {
        $this->serviceUrl = config('services.forensics.url',
            env('FORENSICS_SERVICE_URL', 'http://forensics-service:8002'));
    }

    /**
     * Run full forensic analysis pipeline on an image.
     * Returns a payload suitable for ForensicAnalysis::create().
     */
    public function analyze(Image $image, array $options = []): array
    {
        $imagePath = $this->resolveImagePath($image);

        try {
            $response = Http::timeout(120)
                ->post("{$this->serviceUrl}/analyze", [
                    'image_path'    => $imagePath,
                    'image_id'      => $image->id,
                    'run_ela'       => $options['run_ela']       ?? true,
                    'run_noise'     => $options['run_noise']     ?? true,
                    'run_copy_move' => $options['run_copy_move'] ?? true,
                    'run_ai'        => $options['run_ai']        ?? true,
                    'run_steg'      => $options['run_steg']      ?? false,
                    'ela_quality'   => $options['ela_quality']   ?? 75,
                    'ela_scale'     => $options['ela_scale']     ?? 10,
                ]);

            if ($response->failed()) {
                Log::error("Forensics service error for {$image->id}: " . $response->body());
                return $this->buildFailedPayload();
            }

            $data = $response->json();

            // Store the ELA image to S3 if returned as base64
            $elaPath = null;
            if (!empty($data['ela_image_b64'])) {
                $elaPath = $this->storeElaImage($image->id, $data['ela_image_b64']);
                unset($data['ela_image_b64']);
            }

            return array_merge(
                $this->mapResponseToPayload($data),
                ['ela_image_path' => $elaPath, 'analyzed_at' => now()]
            );

        } catch (\Throwable $e) {
            Log::error("ForensicsService failed for {$image->id}: " . $e->getMessage());
            return $this->buildFailedPayload();
        }
    }

    private function mapResponseToPayload(array $data): array
    {
        return [
            'ela_performed'             => $data['ela_performed']            ?? false,
            'ela_score'                 => $data['ela_score']                ?? null,
            'ela_regions'               => $data['ela_regions']              ?? [],
            'noise_analysis_performed'  => $data['noise_analysis_performed'] ?? false,
            'noise_score'               => $data['noise_score']              ?? null,
            'noise_map'                 => $data['noise_map']                ?? [],
            'copy_move_detected'        => $data['copy_move_detected']       ?? null,
            'copy_move_confidence'      => $data['copy_move_confidence']     ?? null,
            'copy_move_regions'         => $data['copy_move_regions']        ?? [],
            'splicing_detected'         => $data['splicing_detected']        ?? null,
            'splicing_confidence'       => $data['splicing_confidence']      ?? null,
            'ai_detection_performed'    => $data['ai_detection_performed']   ?? false,
            'is_ai_generated'           => $data['is_ai_generated']          ?? null,
            'ai_confidence'             => $data['ai_confidence']            ?? null,
            'ai_model_used'             => $data['ai_model_used']            ?? null,
            'ai_detection_details'      => $data['ai_detection_details']     ?? null,
            'authenticity_verdict'      => $data['authenticity_verdict']     ?? 'unknown',
            'authenticity_score'        => $data['authenticity_score']       ?? null,
            'tampering_indicators'      => $data['tampering_indicators']     ?? [],
            'faces_detected'            => $data['faces_detected']           ?? [],
            'face_count'                => $data['face_count']               ?? 0,
            'objects_detected'          => $data['objects_detected']         ?? [],
            'analysis_version'          => $data['analysis_version']         ?? '1.0',
            'processing_time_ms'        => $data['processing_time_ms']       ?? null,
        ];
    }

    private function storeElaImage(string $imageId, string $base64Data): ?string
    {
        try {
            $binary  = base64_decode($base64Data);
            $path    = "ela/{$imageId}/ela_analysis.png";
            \Illuminate\Support\Facades\Storage::put($path, $binary);
            return $path;
        } catch (\Throwable $e) {
            Log::warning("ELA image storage failed for {$imageId}: " . $e->getMessage());
            return null;
        }
    }

    private function resolveImagePath(Image $image): string
    {
        if ($image->storage_disk === 'local') {
            return \Illuminate\Support\Facades\Storage::path($image->storage_path);
        }
        return \Illuminate\Support\Facades\Storage::disk($image->storage_disk)
            ->temporaryUrl($image->storage_path, now()->addMinutes(15));
    }

    private function buildFailedPayload(): array
    {
        return [
            'ela_performed'          => false,
            'noise_analysis_performed' => false,
            'ai_detection_performed' => false,
            'authenticity_verdict'   => 'unknown',
            'analysis_version'       => '1.0',
            'analyzed_at'            => now(),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AnomalyDetectionService  –  purely in-PHP metadata anomaly analysis
// ─────────────────────────────────────────────────────────────────────────────
class AnomalyDetectionService
{
    /**
     * Detect metadata anomalies from raw metadata + the parsed record.
     * Returns array of anomaly descriptors.
     */
    public function detect(array $rawMetadata, \App\Models\MetadataRecord $record): array
    {
        $issues = [];

        // ── 1. Timestamp regression ───────────────────────────────────────────
        if ($record->date_time_modified && $record->date_time_original) {
            if ($record->date_time_modified->lt($record->date_time_original)) {
                $issues[] = [
                    'type'        => 'timestamp_regression',
                    'severity'    => 'high',
                    'description' => 'Modification timestamp is earlier than capture timestamp',
                    'fields'      => ['DateTime', 'DateTimeOriginal'],
                ];
            }
        }

        // ── 2. GPS null island (0°, 0°) ───────────────────────────────────────
        if ($record->gps_latitude !== null && $record->gps_longitude !== null) {
            if (abs($record->gps_latitude) < 0.001 && abs($record->gps_longitude) < 0.001) {
                $issues[] = [
                    'type'        => 'gps_null_island',
                    'severity'    => 'medium',
                    'description' => 'GPS coordinates point to (0,0) – likely placeholder or stripped',
                    'fields'      => ['GPSLatitude', 'GPSLongitude'],
                ];
            }

            // Out of range
            if (abs($record->gps_latitude) > 90 || abs($record->gps_longitude) > 180) {
                $issues[] = [
                    'type'        => 'gps_out_of_bounds',
                    'severity'    => 'high',
                    'description' => 'GPS coordinates are outside valid WGS-84 range',
                    'fields'      => ['GPSLatitude', 'GPSLongitude'],
                ];
            }
        }

        // ── 3. Editing software fingerprint ───────────────────────────────────
        if ($record->software) {
            $editApps = [
                'Adobe Photoshop' => 'high',
                'GIMP'            => 'medium',
                'Adobe Lightroom' => 'low',
                'Snapseed'        => 'low',
                'Instagram'       => 'low',
                'Facetune'        => 'high',
                'Meitu'           => 'high',
                'Canva'           => 'low',
            ];
            foreach ($editApps as $app => $severity) {
                if (stripos($record->software, $app) !== false) {
                    $issues[] = [
                        'type'        => 'editing_software_detected',
                        'severity'    => $severity,
                        'description' => "Image was processed with {$app}",
                        'fields'      => ['Software'],
                        'value'       => $record->software,
                    ];
                    break;
                }
            }
        }

        // ── 4. Serial number exposure ─────────────────────────────────────────
        if ($record->serial_number) {
            $issues[] = [
                'type'        => 'device_id_exposed',
                'severity'    => 'medium',
                'description' => 'Camera serial number is embedded in metadata – privacy risk',
                'fields'      => ['SerialNumber'],
                'value'       => $record->serial_number,
            ];
        }

        // ── 5. Missing capture date ────────────────────────────────────────────
        if (!$record->date_time_original && $record->has_exif) {
            $issues[] = [
                'type'        => 'missing_capture_date',
                'severity'    => 'medium',
                'description' => 'No original capture date found despite EXIF data being present',
                'fields'      => ['DateTimeOriginal'],
            ];
        }

        // ── 6. Dimension mismatch ──────────────────────────────────────────────
        $exifW = $rawMetadata['EXIF']['ExifImageWidth']  ?? null;
        $exifH = $rawMetadata['EXIF']['ExifImageHeight'] ?? null;
        $actW  = $rawMetadata['Computed']['ActualWidth']  ?? null;
        $actH  = $rawMetadata['Computed']['ActualHeight'] ?? null;

        if ($exifW && $actW && (int)$exifW !== (int)$actW) {
            $issues[] = [
                'type'        => 'dimension_mismatch',
                'severity'    => 'medium',
                'description' => "EXIF reports {$exifW}×{$exifH}px but actual image is {$actW}×{$actH}px",
                'fields'      => ['ExifImageWidth', 'ExifImageHeight'],
            ];
        }

        // ── 7. XMP history chain ───────────────────────────────────────────────
        $xmpHistory = $rawMetadata['XMP']['History'] ?? [];
        if (is_array($xmpHistory) && count($xmpHistory) > 1) {
            $issues[] = [
                'type'        => 'multi_step_edit_history',
                'severity'    => 'low',
                'description' => count($xmpHistory) . ' edit steps found in XMP history chain',
                'fields'      => ['XMP:History'],
                'value'       => count($xmpHistory) . ' steps',
            ];
        }

        // ── 8. Stripped IPTC on professional camera ────────────────────────────
        if ($record->camera_make && !$record->has_iptc) {
            $professionalBrands = ['Canon', 'Nikon', 'Sony', 'Fujifilm', 'Leica', 'Hasselblad'];
            foreach ($professionalBrands as $brand) {
                if (stripos($record->camera_make, $brand) !== false) {
                    $issues[] = [
                        'type'        => 'iptc_stripped',
                        'severity'    => 'low',
                        'description' => "IPTC metadata absent on image from {$brand} camera",
                        'fields'      => ['IPTC'],
                    ];
                    break;
                }
            }
        }

        // ── 9. Future timestamp ────────────────────────────────────────────────
        if ($record->date_time_original && $record->date_time_original->isFuture()) {
            $issues[] = [
                'type'        => 'future_timestamp',
                'severity'    => 'high',
                'description' => 'Capture date is set in the future – likely tampered',
                'fields'      => ['DateTimeOriginal'],
                'value'       => $record->date_time_original->toDateTimeString(),
            ];
        }

        return $issues;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ImageStorageService  –  handles S3/local file storage
// ─────────────────────────────────────────────────────────────────────────────
class ImageStorageService
{
    /**
     * Store an uploaded file and return the storage path.
     */
    public function store(\Illuminate\Http\UploadedFile $file, string $sha256): string
    {
        $ext      = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $datePath = now()->format('Y/m/d');
        $filename = $sha256 . '.' . $ext;
        $path     = "originals/{$datePath}/{$filename}";

        \Illuminate\Support\Facades\Storage::put(
            $path,
            file_get_contents($file->getRealPath()),
            ['visibility' => 'private']
        );

        // Generate thumbnail for images
        $this->generateThumbnail($file->getRealPath(), $path, $ext);

        return $path;
    }

    /**
     * Delete image and its derivatives from storage.
     */
    public function delete(string $storagePath, string $disk = 'default'): void
    {
        $diskInstance = \Illuminate\Support\Facades\Storage::disk($disk);

        $diskInstance->delete($storagePath);

        // Delete thumbnail
        $thumbPath = str_replace('originals/', 'thumbnails/', $storagePath);
        if ($diskInstance->exists($thumbPath)) {
            $diskInstance->delete($thumbPath);
        }

        // Delete ELA image if exists
        preg_match('/originals\/\d+\/\d+\/\d+\/([a-f0-9]+)\./', $storagePath, $m);
        if (!empty($m[1])) {
            $elaPath = "ela/{$m[1]}";
            if ($diskInstance->exists($elaPath)) {
                $diskInstance->deleteDirectory($elaPath);
            }
        }
    }

    private function generateThumbnail(string $sourcePath, string $originalStoragePath, string $ext): void
    {
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) return;

        try {
            $thumbPath = str_replace('originals/', 'thumbnails/', $originalStoragePath);

            $img = \Intervention\Image\Facades\Image::make($sourcePath)
                ->fit(320, 240, function ($c) { $c->upsize(); })
                ->encode($ext === 'jpg' ? 'jpeg' : $ext, 80);

            \Illuminate\Support\Facades\Storage::put($thumbPath, $img->getEncoded());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Thumbnail generation failed: " . $e->getMessage());
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MetadataSearchService  –  Elasticsearch integration
// ─────────────────────────────────────────────────────────────────────────────
class MetadataSearchService
{
    private \Elastic\Elasticsearch\Client $client;

    public function __construct()
    {
        $this->client = \Elastic\Elasticsearch\ClientBuilder::create()
            ->setHosts([
                config('services.elasticsearch.host', 'elasticsearch') . ':' .
                config('services.elasticsearch.port', 9200)
            ])
            ->build();
    }

    /**
     * Full-text search across indexed image metadata.
     * Returns array of image UUIDs.
     */
    public function search(string $query, int $limit = 50): array
    {
        try {
            $response = $this->client->search([
                'index' => 'metaintel_images',
                'body'  => [
                    'size'  => $limit,
                    'query' => [
                        'multi_match' => [
                            'query'  => $query,
                            'fields' => [
                                'original_filename^3',
                                'camera_make^2', 'camera_model^2',
                                'software', 'creator', 'copyright',
                                'caption', 'keywords', 'city', 'country',
                            ],
                            'type' => 'best_fields',
                            'fuzziness' => 'AUTO',
                        ],
                    ],
                    '_source' => ['id'],
                ],
            ]);

            return collect($response['hits']['hits'])
                ->pluck('_source.id')
                ->toArray();

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Elasticsearch search failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Index a fully processed image into Elasticsearch.
     */
    public function index(\App\Models\Image $image): void
    {
        $image->load(['metadata', 'gpsData']);

        try {
            $this->client->index([
                'index' => 'metaintel_images',
                'id'    => $image->id,
                'body'  => [
                    'id'                => $image->id,
                    'original_filename' => $image->original_filename,
                    'mime_type'         => $image->mime_type,
                    'sha256_hash'       => $image->sha256_hash,
                    'status'            => $image->status,
                    'created_at'        => $image->created_at->toIso8601String(),

                    // Camera
                    'camera_make'       => $image->metadata?->camera_make,
                    'camera_model'      => $image->metadata?->camera_model,
                    'lens_model'        => $image->metadata?->lens_model,
                    'software'          => $image->metadata?->software,
                    'serial_number'     => $image->metadata?->serial_number,

                    // IPTC
                    'creator'           => $image->metadata?->creator,
                    'copyright'         => $image->metadata?->copyright,
                    'caption'           => $image->metadata?->caption,
                    'headline'          => $image->metadata?->headline,
                    'keywords'          => $image->metadata?->keywords,

                    // Date
                    'date_taken'        => $image->metadata?->date_time_original?->toIso8601String(),

                    // GPS
                    'city'              => $image->gpsData?->city,
                    'country'           => $image->gpsData?->country,
                    'country_code'      => $image->gpsData?->country_code,
                    'location'          => $image->gpsData
                        ? ['lat' => $image->gpsData->latitude, 'lon' => $image->gpsData->longitude]
                        : null,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Elasticsearch index failed for {$image->id}: " . $e->getMessage());
        }
    }
}
