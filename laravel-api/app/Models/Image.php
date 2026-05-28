<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{HasOne, HasMany, BelongsTo};
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Image extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'media_type',
        'original_filename','stored_filename','storage_path','storage_disk',
        'mime_type','file_size','sha256_hash','md5_hash','phash',
        'status','upload_source','upload_ip','upload_user_agent',
        'batch_id','user_id',
    ];

    protected $casts    = ['file_size' => 'integer', 'deleted_at' => 'datetime'];
    protected $appends  = ['url', 'thumbnail_url', 'formatted_size'];

    public function metadata(): HasOne          { return $this->hasOne(MetadataRecord::class,'image_id'); }
    public function videoMetadata(): HasOne     { return $this->hasOne(VideoMetadata::class,'media_id'); }
    public function videoFrames(): HasMany      { return $this->hasMany(VideoFrame::class,'media_id')->orderBy('timestamp_seconds'); }
    public function videoForensicAnalysis(): HasOne { return $this->hasOne(VideoForensicAnalysis::class,'media_id'); }
    public function forensicAnalysis(): HasOne  { return $this->hasOne(ForensicAnalysis::class,'image_id'); }
    public function gpsData(): HasOne           { return $this->hasOne(GpsData::class,'image_id'); }
    public function timelineEvents(): HasMany   { return $this->hasMany(TimelineEvent::class,'image_id')->orderBy('event_time'); }
    public function batch(): BelongsTo          { return $this->belongsTo(BatchJob::class,'batch_id'); }
    public function user(): BelongsTo           { return $this->belongsTo(User::class); }

    public function isVideo(): bool { return ($this->media_type ?? 'image') === 'video'; }
    public function isImage(): bool { return ($this->media_type ?? 'image') === 'image'; }
    public function getAnyForensics() { return $this->isVideo() ? $this->videoForensicAnalysis : $this->forensicAnalysis; }

    public function getUrlAttribute(): ?string {
        try { return Storage::disk($this->storage_disk)->temporaryUrl($this->storage_path, now()->addHours(2)); }
        catch (\Throwable) { return null; }
    }
    public function getThumbnailUrlAttribute(): ?string {
        $p = str_replace('originals/', $this->isVideo() ? 'video-thumbs/' : 'thumbnails/', $this->storage_path);
        if ($this->isVideo()) $p = preg_replace('/\.\w+$/', '_thumb.jpg', $p);
        try { return Storage::disk($this->storage_disk)->exists($p) ? Storage::disk($this->storage_disk)->temporaryUrl($p, now()->addHours(2)) : null; }
        catch (\Throwable) { return null; }
    }
    public function getFormattedSizeAttribute(): string {
        $b = $this->file_size ?? 0; $u = ['B','KB','MB','GB']; $i = 0;
        while ($b >= 1024 && $i < 3) { $b /= 1024; $i++; }
        return round($b, 2) . ' ' . $u[$i];
    }

    public function scopeCompleted($q)  { return $q->where('status','completed'); }
    public function scopeWithGps($q)    { return $q->whereHas('gpsData'); }
    public function scopeVideos($q)     { return $q->where('media_type','video'); }
    public function scopeImages($q)     { return $q->where('media_type','image'); }
    public function scopeForBatch($q, int $id) { return $q->where('batch_id', $id); }

    public function markAsProcessing(): void { $this->update(['status'=>'processing']); }
    public function markAsCompleted(): void  { $this->update(['status'=>'completed']); }
    public function markAsFailed(): void     { $this->update(['status'=>'failed']); }

    public function getAnalysisSummary(): array {
        $fa = $this->isVideo() ? $this->videoForensicAnalysis : $this->forensicAnalysis;
        $base = ['id'=>$this->id,'filename'=>$this->original_filename,'type'=>$this->media_type??'image',
                 'size'=>$this->formatted_size,'status'=>$this->status,'has_gps'=>$this->gpsData!==null,
                 'authenticity'=>$fa?->authenticity_verdict??'unknown','created_at'=>$this->created_at?->toIso8601String()];
        if ($this->isVideo()) {
            $vm = $this->videoMetadata;
            return array_merge($base,['duration'=>$vm?->formatted_duration,'resolution'=>$vm?->resolution,
                'codec'=>$vm?->video_codec?strtoupper($vm->video_codec):null,
                'deepfake_detected'=>$this->videoForensicAnalysis?->deepfake_detected,
                'suspicious_frames'=>$this->videoForensicAnalysis?->suspicious_frame_count??0,
                'anomaly_count'=>$vm?->anomaly_count??0,'has_gps_track'=>!empty($vm?->gps_track)]);
        }
        return array_merge($base,['is_ai_generated'=>$this->forensicAnalysis?->is_ai_generated,
            'anomaly_count'=>$this->metadata?->anomaly_count??0]);
    }
}
