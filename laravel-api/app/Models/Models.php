<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ─────────────────────────────────────────────────────────────────────────────
// MetadataRecord Model
// ─────────────────────────────────────────────────────────────────────────────
class MetadataRecord extends Model
{
    protected $table = 'metadata_records';

    protected $fillable = [
        'image_id', 'width', 'height', 'color_space', 'bit_depth',
        'camera_make', 'camera_model', 'lens_model', 'software', 'firmware_version', 'serial_number',
        'exposure_time', 'f_number', 'iso_speed', 'focal_length', 'focal_length_35mm',
        'flash', 'white_balance', 'metering_mode', 'exposure_mode', 'scene_capture_type',
        'date_time_original', 'date_time_digitized', 'date_time_modified',
        'timezone_offset', 'sub_sec_time',
        'gps_latitude', 'gps_longitude', 'gps_altitude',
        'copyright', 'creator', 'credit', 'source', 'caption', 'headline', 'keywords', 'subject_code',
        'icc_profile_name', 'color_profile_description', 'rendering_intent',
        'has_exif', 'has_iptc', 'has_xmp', 'has_icc', 'has_maker_notes',
        'raw_exif', 'raw_iptc', 'raw_xmp', 'raw_maker_notes', 'raw_icc',
        'anomaly_flags', 'anomaly_count',
    ];

    protected $casts = [
        'width'          => 'integer',
        'height'         => 'integer',
        'bit_depth'      => 'integer',
        'gps_latitude'   => 'float',
        'gps_longitude'  => 'float',
        'gps_altitude'   => 'float',
        'date_time_original'  => 'datetime',
        'date_time_digitized' => 'datetime',
        'date_time_modified'  => 'datetime',
        'has_exif'       => 'boolean',
        'has_iptc'       => 'boolean',
        'has_xmp'        => 'boolean',
        'has_icc'        => 'boolean',
        'has_maker_notes'=> 'boolean',
        'keywords'       => 'array',
        'raw_exif'       => 'array',
        'raw_iptc'       => 'array',
        'raw_xmp'        => 'array',
        'raw_maker_notes'=> 'array',
        'raw_icc'        => 'array',
        'anomaly_flags'  => 'array',
        'anomaly_count'  => 'integer',
    ];

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'image_id');
    }

    /**
     * Get a structured tree of all metadata fields by category
     */
    public function toMetadataTree(): array
    {
        return [
            'basic' => [
                'Dimensions'    => $this->width && $this->height ? "{$this->width} × {$this->height} px" : null,
                'Color Space'   => $this->color_space,
                'Bit Depth'     => $this->bit_depth,
            ],
            'camera' => [
                'Make'          => $this->camera_make,
                'Model'         => $this->camera_model,
                'Lens'          => $this->lens_model,
                'Software'      => $this->software,
                'Firmware'      => $this->firmware_version,
                'Serial Number' => $this->serial_number,
            ],
            'capture' => [
                'Exposure'      => $this->exposure_time,
                'Aperture'      => $this->f_number ? "f/{$this->f_number}" : null,
                'ISO'           => $this->iso_speed,
                'Focal Length'  => $this->focal_length,
                'FL (35mm eq.)' => $this->focal_length_35mm,
                'Flash'         => $this->flash,
                'White Balance' => $this->white_balance,
                'Metering'      => $this->metering_mode,
                'Exposure Mode' => $this->exposure_mode,
            ],
            'timestamps' => [
                'Date/Time Original'  => $this->date_time_original?->toDateTimeString(),
                'Date/Time Digitized' => $this->date_time_digitized?->toDateTimeString(),
                'Date/Time Modified'  => $this->date_time_modified?->toDateTimeString(),
                'Timezone Offset'     => $this->timezone_offset,
            ],
            'gps' => [
                'Latitude'  => $this->gps_latitude,
                'Longitude' => $this->gps_longitude,
                'Altitude'  => $this->gps_altitude ? "{$this->gps_altitude} m" : null,
            ],
            'iptc' => [
                'Copyright' => $this->copyright,
                'Creator'   => $this->creator,
                'Credit'    => $this->credit,
                'Source'    => $this->source,
                'Caption'   => $this->caption,
                'Headline'  => $this->headline,
                'Keywords'  => $this->keywords,
            ],
            'color_profile' => [
                'ICC Profile'   => $this->icc_profile_name,
                'Description'   => $this->color_profile_description,
                'Rendering'     => $this->rendering_intent,
            ],
            'standards' => [
                'EXIF'        => $this->has_exif,
                'IPTC'        => $this->has_iptc,
                'XMP'         => $this->has_xmp,
                'ICC'         => $this->has_icc,
                'MakerNotes'  => $this->has_maker_notes,
            ],
            'anomalies' => $this->anomaly_flags ?? [],
            'raw' => [
                'exif'        => $this->raw_exif,
                'iptc'        => $this->raw_iptc,
                'xmp'         => $this->raw_xmp,
                'maker_notes' => $this->raw_maker_notes,
                'icc'         => $this->raw_icc,
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GpsData Model
// ─────────────────────────────────────────────────────────────────────────────
class GpsData extends Model
{
    protected $table = 'gps_data';

    protected $fillable = [
        'image_id', 'latitude', 'longitude', 'altitude', 'altitude_ref',
        'direction', 'direction_ref', 'speed', 'speed_ref',
        'gps_timestamp', 'gps_datestamp', 'map_datum', 'dop',
        'measure_mode', 'satellites', 'status', 'processing_method',
        'country_code', 'country', 'state', 'city', 'district',
        'street', 'postal_code', 'place_name', 'full_address_components',
    ];

    protected $casts = [
        'latitude'                => 'float',
        'longitude'               => 'float',
        'altitude'                => 'float',
        'full_address_components' => 'array',
    ];

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'image_id');
    }

    public function getFormattedCoordinates(): string
    {
        $lat = abs($this->latitude) . '° ' . ($this->latitude >= 0 ? 'N' : 'S');
        $lon = abs($this->longitude) . '° ' . ($this->longitude >= 0 ? 'E' : 'W');
        return "$lat, $lon";
    }

    public function getGoogleMapsUrl(): string
    {
        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }

    public function toGeoJSON(): array
    {
        return [
            'type'       => 'Feature',
            'geometry'   => [
                'type'        => 'Point',
                'coordinates' => [$this->longitude, $this->latitude],
            ],
            'properties' => [
                'image_id'  => $this->image_id,
                'altitude'  => $this->altitude,
                'city'      => $this->city,
                'country'   => $this->country,
                'address'   => $this->full_address_components,
                'timestamp' => $this->image?->metadata?->date_time_original,
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ForensicAnalysis Model
// ─────────────────────────────────────────────────────────────────────────────
class ForensicAnalysis extends Model
{
    protected $table = 'forensic_analysis';

    protected $fillable = [
        'image_id',
        'ela_performed', 'ela_score', 'ela_image_path', 'ela_regions',
        'noise_analysis_performed', 'noise_score', 'noise_map',
        'copy_move_detected', 'copy_move_confidence', 'copy_move_regions',
        'splicing_detected', 'splicing_confidence',
        'ai_detection_performed', 'is_ai_generated', 'ai_confidence',
        'ai_model_used', 'ai_detection_details',
        'authenticity_verdict', 'authenticity_score', 'tampering_indicators',
        'prnu_camera_fingerprint', 'prnu_correlation', 'prnu_match',
        'steg_analysis_performed', 'steg_detected', 'steg_confidence',
        'faces_detected', 'face_count', 'objects_detected',
        'analysis_version', 'analyzed_at', 'processing_time_ms',
    ];

    protected $casts = [
        'ela_performed'              => 'boolean',
        'ela_score'                  => 'float',
        'ela_regions'                => 'array',
        'noise_analysis_performed'   => 'boolean',
        'noise_score'                => 'float',
        'noise_map'                  => 'array',
        'copy_move_detected'         => 'boolean',
        'copy_move_confidence'       => 'float',
        'copy_move_regions'          => 'array',
        'splicing_detected'          => 'boolean',
        'splicing_confidence'        => 'float',
        'ai_detection_performed'     => 'boolean',
        'is_ai_generated'            => 'boolean',
        'ai_confidence'              => 'float',
        'ai_detection_details'       => 'array',
        'authenticity_score'         => 'float',
        'tampering_indicators'       => 'array',
        'prnu_correlation'           => 'float',
        'prnu_match'                 => 'boolean',
        'steg_analysis_performed'    => 'boolean',
        'steg_detected'              => 'boolean',
        'steg_confidence'            => 'float',
        'faces_detected'             => 'array',
        'face_count'                 => 'integer',
        'objects_detected'           => 'array',
        'analyzed_at'                => 'datetime',
        'processing_time_ms'         => 'integer',
    ];

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'image_id');
    }

    public function getRiskLevelAttribute(): string
    {
        return match($this->authenticity_verdict) {
            'authentic', 'likely_authentic' => 'low',
            'suspicious'                    => 'medium',
            'likely_tampered', 'tampered'   => 'high',
            'ai_generated'                  => 'ai',
            default                         => 'unknown',
        };
    }

    public function getVerdictColorAttribute(): string
    {
        return match($this->risk_level) {
            'low'     => '#22c55e',
            'medium'  => '#f59e0b',
            'high'    => '#ef4444',
            'ai'      => '#8b5cf6',
            default   => '#6b7280',
        };
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// TimelineEvent Model
// ─────────────────────────────────────────────────────────────────────────────
class TimelineEvent extends Model
{
    protected $table = 'timeline_events';

    protected $fillable = [
        'image_id', 'batch_id', 'event_type', 'event_source',
        'event_time', 'timezone', 'description', 'metadata',
        'is_anomaly', 'anomaly_reason',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'metadata'   => 'array',
        'is_anomaly' => 'boolean',
    ];

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'image_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BatchJob::class, 'batch_id');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// BatchJob Model
// ─────────────────────────────────────────────────────────────────────────────
class BatchJob extends Model
{
    protected $table = 'batch_jobs';

    protected $fillable = [
        'name', 'uuid', 'status', 'total_images', 'processed_images',
        'failed_images', 'options', 'user_id', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'options'      => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function images(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Image::class, 'batch_id');
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_images === 0) return 0;
        return round(($this->processed_images / $this->total_images) * 100, 1);
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'completed'  => '#22c55e',
            'processing' => '#3b82f6',
            'failed'     => '#ef4444',
            'cancelled'  => '#6b7280',
            default      => '#f59e0b',
        };
    }
}
