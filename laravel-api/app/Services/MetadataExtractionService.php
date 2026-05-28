<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Facades\{Http, Storage, Log};

class MetadataExtractionService
{
    private string $serviceUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->serviceUrl = config('services.metadata.url', env('METADATA_SERVICE_URL', 'http://metadata-service:8001'));
        $this->timeout    = 60;
    }

    /**
     * Extract all metadata from an image via the Python microservice
     */
    public function extract(Image $image): array
    {
        try {
            // Get a signed URL or local path
            $imagePath = $this->resolveImagePath($image);

            $response = Http::timeout($this->timeout)
                ->post("{$this->serviceUrl}/extract", [
                    'image_path'  => $imagePath,
                    'image_id'    => $image->id,
                    'mime_type'   => $image->mime_type,
                    'options'     => [
                        'extract_maker_notes' => true,
                        'extract_xmp'         => true,
                        'extract_iptc'        => true,
                        'extract_icc'         => true,
                        'decode_unicode'      => true,
                        'struct'              => true,
                        'binary_data'         => false, // skip large binary blobs
                    ],
                ]);

            if ($response->failed()) {
                Log::error("Metadata service error for {$image->id}: " . $response->body());
                throw new \RuntimeException("Metadata service returned: " . $response->status());
            }

            return $response->json('data', []);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning("Metadata service unreachable, falling back to local ExifTool: " . $e->getMessage());
            return $this->extractWithLocalExiftool($image);
        }
    }

    /**
     * Fallback: extract metadata locally using ExifTool binary
     */
    private function extractWithLocalExiftool(Image $image): array
    {
        $exiftoolPath = config('metaintel.exiftool_path', '/usr/bin/exiftool');
        $localPath    = $this->downloadToTemp($image);

        try {
            $cmd    = escapeshellcmd("{$exiftoolPath} -json -struct -all:all -g " . escapeshellarg($localPath));
            $output = shell_exec($cmd);

            if (!$output) return [];

            $parsed = json_decode($output, true);
            return $parsed[0] ?? [];

        } finally {
            if (file_exists($localPath)) {
                unlink($localPath);
            }
        }
    }

    /**
     * Compute perceptual hash (pHash) of an image
     */
    public function computePerceptualHash(Image $image): ?string
    {
        try {
            $imagePath = $this->resolveImagePath($image);

            $response = Http::timeout(30)
                ->post("{$this->serviceUrl}/phash", ['image_path' => $imagePath]);

            return $response->json('phash');
        } catch (\Throwable $e) {
            Log::warning("pHash computation failed for {$image->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate metadata consistency (timestamps, GPS plausibility, etc.)
     */
    public function validateConsistency(array $metadata): array
    {
        $issues = [];

        // Timestamp consistency
        $original  = $metadata['EXIF']['DateTimeOriginal']  ?? null;
        $digitized = $metadata['EXIF']['DateTimeDigitized'] ?? null;
        $modified  = $metadata['EXIF']['DateTime']          ?? null;

        if ($original && $modified) {
            try {
                $origTs = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $original);
                $modTs  = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $modified);
                if ($modTs->lt($origTs)) {
                    $issues[] = [
                        'type'        => 'timestamp_inconsistency',
                        'severity'    => 'high',
                        'description' => 'Modification date is before capture date',
                        'fields'      => ['DateTimeOriginal', 'DateTime'],
                    ];
                }
            } catch (\Throwable) {}
        }

        // GPS sanity
        $lat = $metadata['Composite']['GPSLatitude']  ?? null;
        $lon = $metadata['Composite']['GPSLongitude'] ?? null;
        if ($lat !== null && $lon !== null) {
            if (abs((float)$lat) > 90 || abs((float)$lon) > 180) {
                $issues[] = [
                    'type'        => 'gps_out_of_bounds',
                    'severity'    => 'high',
                    'description' => 'GPS coordinates are out of valid range',
                    'fields'      => ['GPSLatitude', 'GPSLongitude'],
                ];
            }
            // Detect 0,0 (null island)
            if ((float)$lat === 0.0 && (float)$lon === 0.0) {
                $issues[] = [
                    'type'        => 'gps_null_island',
                    'severity'    => 'medium',
                    'description' => 'GPS coordinates point to (0,0) – likely default/placeholder',
                    'fields'      => ['GPSLatitude', 'GPSLongitude'],
                ];
            }
        }

        // Software fingerprint inconsistency
        $software  = $metadata['EXIF']['Software']        ?? '';
        $cameraMake= $metadata['EXIF']['Make']            ?? '';
        if ($software && $cameraMake) {
            $editSoftware = ['Adobe Photoshop', 'GIMP', 'Lightroom', 'Snapseed', 'VSCO', 'Instagram'];
            foreach ($editSoftware as $editApp) {
                if (stripos($software, $editApp) !== false) {
                    $issues[] = [
                        'type'        => 'editing_software_detected',
                        'severity'    => 'low',
                        'description' => "Image processed with {$editApp}",
                        'fields'      => ['Software'],
                        'value'       => $software,
                    ];
                    break;
                }
            }
        }

        // Missing critical fields
        if (empty($metadata['EXIF']['DateTimeOriginal'])) {
            $issues[] = [
                'type'        => 'missing_capture_date',
                'severity'    => 'medium',
                'description' => 'No original capture timestamp found',
                'fields'      => ['DateTimeOriginal'],
            ];
        }

        // Serial number exposure
        if (!empty($metadata['EXIF']['SerialNumber']) || !empty($metadata['MakerNotes']['SerialNumber'])) {
            $issues[] = [
                'type'        => 'device_id_exposed',
                'severity'    => 'medium',
                'description' => 'Camera serial number embedded in metadata',
                'fields'      => ['SerialNumber'],
            ];
        }

        return $issues;
    }

    private function resolveImagePath(Image $image): string
    {
        // For local storage, return the absolute path
        if ($image->storage_disk === 'local') {
            return Storage::path($image->storage_path);
        }

        // For S3/MinIO, generate a temporary signed URL
        return Storage::disk($image->storage_disk)->temporaryUrl(
            $image->storage_path,
            now()->addMinutes(10)
        );
    }

    private function downloadToTemp(Image $image): string
    {
        $tempPath = sys_get_temp_dir() . '/metaintel_' . $image->id . '.' . pathinfo($image->stored_filename, PATHINFO_EXTENSION);

        $content = Storage::disk($image->storage_disk)->get($image->storage_path);
        file_put_contents($tempPath, $content);

        return $tempPath;
    }
}
