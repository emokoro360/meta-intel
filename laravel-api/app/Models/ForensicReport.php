<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForensicReport extends Model
{
    protected $table = 'forensic_reports';

    protected $fillable = [
        'uuid', 'user_id', 'title', 'report_type',
        'image_ids', 'sections', 'format', 'file_path',
        'status', 'metadata', 'expires_at',
    ];

    protected $casts = [
        'image_ids'  => 'array',
        'sections'   => 'array',
        'metadata'   => 'array',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

namespace App\Services;

use App\Models\{Image, ForensicReport};

class ReportGeneratorService
{
    /**
     * Validate and prepare report generation request.
     */
    public function validateImages(array $imageIds): array
    {
        return Image::whereIn('id', $imageIds)
            ->where('status', 'completed')
            ->pluck('id')
            ->toArray();
    }

    /**
     * Estimate report generation time in seconds.
     */
    public function estimateTime(int $imageCount, string $format): int
    {
        $base = match ($format) {
            'pdf'  => 10,
            'html' => 5,
            'csv'  => 2,
            'json' => 2,
            default => 5,
        };
        return $base + ($imageCount * 0.5);
    }

    /**
     * Get available report sections.
     */
    public function getAvailableSections(): array
    {
        return [
            'metadata'   => 'Full metadata tree (EXIF, IPTC, XMP, ICC)',
            'forensics'  => 'Forensic analysis results (ELA, noise, AI detection)',
            'gps'        => 'GPS data and reverse geocoding',
            'timeline'   => 'Activity timeline events',
            'anomalies'  => 'Detected anomalies and risk flags',
            'technical'  => 'Technical file information (hashes, dimensions)',
        ];
    }
}
