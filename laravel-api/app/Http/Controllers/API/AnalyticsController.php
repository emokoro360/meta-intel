<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\{Image, MetadataRecord, GpsData, VideoMetadata, VideoForensicAnalysis, ForensicAnalysis};
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function dashboard(): JsonResponse
    {
        // ─── Totals ───────────────────────────────────────────────────────────
        $totalMedia     = Image::count();
        $totalImages    = Image::images()->count();
        $totalVideos    = Image::videos()->count();
        $processedToday = Image::whereDate('updated_at', today())->where('status','completed')->count();
        $withGps        = GpsData::count();
        $imageAnomalies = MetadataRecord::where('anomaly_count','>',0)->count();
        $videoAnomalies = VideoMetadata::where('anomaly_count','>',0)->count();
        $aiGenerated    = ForensicAnalysis::where('is_ai_generated',true)->count();
        $deepfakes      = VideoForensicAnalysis::where('deepfake_detected',true)->count();
        $tampered       = ForensicAnalysis::whereIn('authenticity_verdict',['likely_tampered','tampered'])->count()
                        + VideoForensicAnalysis::whereIn('authenticity_verdict',['likely_tampered','tampered'])->count();

        // ─── Top cameras (images) ─────────────────────────────────────────────
        $topCameras = MetadataRecord::selectRaw('camera_make, camera_model, COUNT(*) as count')
            ->whereNotNull('camera_make')->groupBy('camera_make','camera_model')
            ->orderByDesc('count')->limit(8)->get();

        // ─── Top video devices ────────────────────────────────────────────────
        $topVideoDevices = VideoMetadata::selectRaw('make, model, COUNT(*) as count')
            ->whereNotNull('make')->groupBy('make','model')
            ->orderByDesc('count')->limit(5)->get();

        // ─── Top video codecs ─────────────────────────────────────────────────
        $topCodecs = VideoMetadata::selectRaw('video_codec, COUNT(*) as count')
            ->whereNotNull('video_codec')->groupBy('video_codec')
            ->orderByDesc('count')->limit(6)->get();

        // ─── Top software (images) ────────────────────────────────────────────
        $topSoftware = MetadataRecord::selectRaw('software, COUNT(*) as count')
            ->whereNotNull('software')->groupBy('software')
            ->orderByDesc('count')->limit(6)->get();

        // ─── Upload trend (last 30 days) ──────────────────────────────────────
        $uploadTrend = Image::selectRaw("DATE(created_at) as date, media_type, COUNT(*) as count")
            ->where('created_at','>=',now()->subDays(30))
            ->groupBy('date','media_type')->orderBy('date')->get()
            ->groupBy('date')
            ->map(fn($group) => [
                'date'   => $group->first()->date,
                'images' => $group->firstWhere('media_type','image')?->count ?? 0,
                'videos' => $group->firstWhere('media_type','video')?->count ?? 0,
                'total'  => $group->sum('count'),
            ])->values();

        // ─── Video duration stats ─────────────────────────────────────────────
        $videoDurationStats = VideoMetadata::selectRaw(
            'AVG(duration_seconds) as avg_duration,
             MAX(duration_seconds) as max_duration,
             SUM(duration_seconds) as total_duration,
             COUNT(*) as count'
        )->first();

        // ─── GPS coverage ─────────────────────────────────────────────────────
        $topCountries = GpsData::selectRaw('country, country_code, COUNT(*) as count')
            ->whereNotNull('country')->groupBy('country','country_code')
            ->orderByDesc('count')->limit(8)->get();

        // ─── Recent activity ──────────────────────────────────────────────────
        $recentActivity = Image::select(['id','media_type','original_filename','status','mime_type','created_at'])
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn($m) => [
                'id'       => $m->id,
                'type'     => $m->media_type,
                'filename' => $m->original_filename,
                'status'   => $m->status,
                'created_at'=> $m->created_at,
            ]);

        return response()->json([
            'totals' => [
                'media'          => $totalMedia,
                'images'         => $totalImages,
                'videos'         => $totalVideos,
                'processed_today'=> $processedToday,
                'with_gps'       => $withGps,
                'anomalies'      => $imageAnomalies + $videoAnomalies,
                'ai_generated'   => $aiGenerated,
                'deepfakes'      => $deepfakes,
                'tampered'       => $tampered,
            ],
            'top_cameras'       => $topCameras,
            'top_video_devices' => $topVideoDevices,
            'top_codecs'        => $topCodecs,
            'top_software'      => $topSoftware,
            'top_countries'     => $topCountries,
            'upload_trend'      => $uploadTrend,
            'video_stats' => [
                'avg_duration_s'  => round($videoDurationStats->avg_duration ?? 0),
                'max_duration_s'  => round($videoDurationStats->max_duration ?? 0),
                'total_duration_s'=> round($videoDurationStats->total_duration ?? 0),
                'total_videos'    => $videoDurationStats->count ?? 0,
            ],
            'recent_activity'   => $recentActivity,
        ]);
    }
}
