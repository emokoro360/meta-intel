<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Facades\Storage;

// ─────────────────────────────────────────────────────────────────────────────
// VideoMetadata Model
// ─────────────────────────────────────────────────────────────────────────────
class VideoMetadata extends Model
{
    protected $table    = 'video_metadata';
    protected $fillable = [
        'media_id', 'container_format', 'format_long_name', 'duration_seconds',
        'bitrate_bps', 'file_size_bytes', 'nb_streams', 'format_tags',
        'video_codec', 'video_codec_long', 'video_profile',
        'width', 'height', 'display_aspect_ratio', 'pix_fmt',
        'frame_rate', 'avg_frame_rate', 'nb_frames', 'video_bitrate_bps',
        'color_space', 'color_transfer', 'color_primaries', 'is_hdr', 'rotation',
        'audio_streams', 'audio_track_count', 'subtitle_streams', 'chapters',
        'encoder', 'creation_time', 'creation_timezone',
        'make', 'model', 'software', 'handler_name',
        'gps_latitude', 'gps_longitude', 'gps_altitude', 'gps_track',
        'scene_count', 'keyframe_count', 'average_scene_length_s',
        'raw_ffprobe', 'raw_mediainfo', 'raw_container_tags',
        'raw_video_tags', 'raw_audio_tags',
        'anomaly_flags', 'anomaly_count',
    ];

    protected $casts = [
        'duration_seconds'       => 'float',
        'bitrate_bps'            => 'integer',
        'avg_frame_rate'         => 'float',
        'nb_frames'              => 'integer',
        'video_bitrate_bps'      => 'integer',
        'is_hdr'                 => 'boolean',
        'gps_latitude'           => 'float',
        'gps_longitude'          => 'float',
        'gps_altitude'           => 'float',
        'audio_track_count'      => 'integer',
        'scene_count'            => 'integer',
        'keyframe_count'         => 'integer',
        'average_scene_length_s' => 'float',
        'audio_streams'          => 'array',
        'subtitle_streams'       => 'array',
        'chapters'               => 'array',
        'gps_track'              => 'array',
        'raw_ffprobe'            => 'array',
        'raw_mediainfo'          => 'array',
        'raw_container_tags'     => 'array',
        'raw_video_tags'         => 'array',
        'raw_audio_tags'         => 'array',
        'anomaly_flags'          => 'array',
        'creation_time'          => 'datetime',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'media_id');
    }

    public function frames(): HasMany
    {
        return $this->hasMany(VideoFrame::class, 'media_id', 'media_id');
    }

    // ── Computed helpers ───────────────────────────────────────────────────────

    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_seconds) return '—';
        $secs = (int) $this->duration_seconds;
        $h    = intdiv($secs, 3600);
        $m    = intdiv($secs % 3600, 60);
        $s    = $secs % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    public function getFormattedBitrateAttribute(): string
    {
        if (!$this->bitrate_bps) return '—';
        $kbps = $this->bitrate_bps / 1000;
        return $kbps >= 1000
            ? round($kbps / 1000, 1) . ' Mb/s'
            : round($kbps) . ' kb/s';
    }

    public function getResolutionAttribute(): string
    {
        if (!$this->width || !$this->height) return '—';
        $label = match (true) {
            $this->height >= 2160 => '4K UHD',
            $this->height >= 1440 => '1440p QHD',
            $this->height >= 1080 => '1080p FHD',
            $this->height >= 720  => '720p HD',
            $this->height >= 480  => '480p SD',
            default               => 'SD',
        };
        return "{$this->width}×{$this->height} ({$label})";
    }

    public function getHasGpsAttribute(): bool
    {
        return $this->gps_latitude !== null && $this->gps_longitude !== null;
    }

    public function getHasGpsTrackAttribute(): bool
    {
        return !empty($this->gps_track) && count($this->gps_track) > 0;
    }

    /**
     * Return structured metadata tree for the API / frontend.
     */
    public function toMetadataTree(): array
    {
        return [
            'container' => [
                'Format'           => $this->container_format ? strtoupper($this->container_format) : null,
                'Format Name'      => $this->format_long_name,
                'Duration'         => $this->formatted_duration,
                'Overall Bitrate'  => $this->formatted_bitrate,
                'Streams'          => $this->nb_streams,
            ],
            'video_stream' => [
                'Codec'            => $this->video_codec ? strtoupper($this->video_codec) : null,
                'Profile'          => $this->video_profile,
                'Resolution'       => $this->resolution,
                'Aspect Ratio'     => $this->display_aspect_ratio,
                'Frame Rate'       => $this->avg_frame_rate ? round($this->avg_frame_rate, 3) . ' fps' : null,
                'Total Frames'     => $this->nb_frames ? number_format($this->nb_frames) : null,
                'Bitrate'          => $this->video_bitrate_bps
                    ? round($this->video_bitrate_bps / 1000) . ' kb/s' : null,
                'Pixel Format'     => $this->pix_fmt,
                'Color Space'      => $this->color_space,
                'Color Transfer'   => $this->color_transfer,
                'HDR'              => $this->is_hdr ? 'Yes' : 'No',
                'Rotation'         => $this->rotation ? $this->rotation . '°' : null,
            ],
            'audio_tracks' => $this->audio_streams ?? [],
            'encoding' => [
                'Encoder'          => $this->encoder,
                'Creation Time'    => $this->creation_time?->toDateTimeString(),
                'Timezone'         => $this->creation_timezone,
            ],
            'device' => [
                'Make'             => $this->make,
                'Model'            => $this->model,
                'Software'         => $this->software,
                'Handler'          => $this->handler_name,
            ],
            'gps' => $this->has_gps ? [
                'Latitude'         => $this->gps_latitude,
                'Longitude'        => $this->gps_longitude,
                'Altitude'         => $this->gps_altitude ? "{$this->gps_altitude} m" : null,
                'GPS Track Points' => $this->gps_track ? count($this->gps_track) : 0,
            ] : null,
            'content' => [
                'Scenes Detected'  => $this->scene_count,
                'Keyframes'        => $this->keyframe_count,
                'Avg Scene Length' => $this->average_scene_length_s
                    ? round($this->average_scene_length_s, 1) . 's' : null,
            ],
            'chapters'  => $this->chapters ?? [],
            'subtitles' => $this->subtitle_streams ?? [],
            'anomalies' => $this->anomaly_flags ?? [],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// VideoFrame Model
// ─────────────────────────────────────────────────────────────────────────────
class VideoFrame extends Model
{
    protected $table    = 'video_frames';
    protected $fillable = [
        'media_id', 'timestamp_seconds', 'frame_number', 'storage_path',
        'frame_type', 'ela_score', 'noise_score', 'is_suspicious',
        'forensic_details', 'faces_detected', 'face_count', 'objects_detected',
    ];

    protected $casts = [
        'timestamp_seconds' => 'float',
        'ela_score'         => 'float',
        'noise_score'       => 'float',
        'is_suspicious'     => 'boolean',
        'forensic_details'  => 'array',
        'faces_detected'    => 'array',
        'objects_detected'  => 'array',
    ];

    protected $appends = ['thumbnail_url'];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'media_id');
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->storage_path) return null;
        return Storage::temporaryUrl($this->storage_path, now()->addHour());
    }

    public function getFormattedTimestampAttribute(): string
    {
        $s = (int) $this->timestamp_seconds;
        $m = intdiv($s, 60);
        return sprintf('%d:%02d.%03d', $m, $s % 60,
            (int)(($this->timestamp_seconds - floor($this->timestamp_seconds)) * 1000)
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// VideoForensicAnalysis Model
// ─────────────────────────────────────────────────────────────────────────────
class VideoForensicAnalysis extends Model
{
    protected $table    = 'video_forensic_analysis';
    protected $fillable = [
        'media_id',
        'reencoding_detected', 'reencoding_confidence', 'reencoding_evidence',
        'temporal_splicing_detected', 'splicing_confidence', 'splice_points',
        'av_sync_anomaly', 'av_sync_offset_ms',
        'deepfake_detected', 'deepfake_confidence', 'deepfake_model_used',
        'deepfake_frame_scores',
        'avg_ela_score', 'max_ela_score', 'suspicious_frame_count',
        'codec_mismatch_detected', 'codec_anomalies',
        'authenticity_verdict', 'authenticity_score', 'tampering_indicators',
        'frames_analyzed', 'analysis_version', 'analyzed_at', 'processing_time_ms',
    ];

    protected $casts = [
        'reencoding_detected'        => 'boolean',
        'reencoding_confidence'      => 'float',
        'reencoding_evidence'        => 'array',
        'temporal_splicing_detected' => 'boolean',
        'splicing_confidence'        => 'float',
        'splice_points'              => 'array',
        'av_sync_anomaly'            => 'boolean',
        'av_sync_offset_ms'          => 'float',
        'deepfake_detected'          => 'boolean',
        'deepfake_confidence'        => 'float',
        'deepfake_frame_scores'      => 'array',
        'avg_ela_score'              => 'float',
        'max_ela_score'              => 'float',
        'codec_mismatch_detected'    => 'boolean',
        'codec_anomalies'            => 'array',
        'authenticity_score'         => 'float',
        'tampering_indicators'       => 'array',
        'analyzed_at'                => 'datetime',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'media_id');
    }

    public function getRiskLevelAttribute(): string
    {
        return match ($this->authenticity_verdict) {
            'authentic', 'likely_authentic' => 'low',
            'suspicious'                    => 'medium',
            'likely_tampered', 'tampered'   => 'high',
            'ai_generated'                  => 'ai',
            default                         => 'unknown',
        };
    }
}
