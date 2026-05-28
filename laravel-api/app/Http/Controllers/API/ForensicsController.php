<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Models\{Image,VideoFrame,VideoForensicAnalysis,ForensicAnalysis};
use Illuminate\Http\{JsonResponse,Request};

class ForensicsController extends Controller
{
    public function show(string $mediaId): JsonResponse
    {
        $media = Image::findOrFail($mediaId);
        if ($media->isVideo()) {
            $fa = VideoForensicAnalysis::where('media_id',$mediaId)->first();
            if (!$fa) return response()->json(['error'=>'Video forensic analysis not yet available'],404);
            return response()->json(['media_id'=>$mediaId,'type'=>'video','forensics'=>$fa,
                'risk_level'=>$fa->risk_level,'verdict_color'=>$this->vColor($fa->authenticity_verdict),
                'summary'=>['authenticity'=>$fa->authenticity_verdict,'authenticity_score'=>$fa->authenticity_score,
                    'deepfake_detected'=>$fa->deepfake_detected,'deepfake_confidence'=>$fa->deepfake_confidence,
                    'temporal_splicing_detected'=>$fa->temporal_splicing_detected,'splicing_confidence'=>$fa->splicing_confidence,
                    'reencoding_detected'=>$fa->reencoding_detected,'av_sync_anomaly'=>$fa->av_sync_anomaly,
                    'av_sync_offset_ms'=>$fa->av_sync_offset_ms,'suspicious_frame_count'=>$fa->suspicious_frame_count,
                    'frames_analyzed'=>$fa->frames_analyzed,'tampering_indicators'=>$fa->tampering_indicators,
                    'processing_time_ms'=>$fa->processing_time_ms]]);
        }
        $fa = ForensicAnalysis::where('image_id',$mediaId)->first();
        if (!$fa) return response()->json(['error'=>'Forensic analysis not yet available'],404);
        return response()->json(['media_id'=>$mediaId,'type'=>'image','forensics'=>$fa,
            'risk_level'=>$fa->risk_level,'verdict_color'=>$this->vColor($fa->authenticity_verdict),
            'ela_url'=>$fa->ela_image_path?\Storage::temporaryUrl($fa->ela_image_path,now()->addHour()):null,
            'summary'=>['authenticity'=>$fa->authenticity_verdict,'authenticity_score'=>$fa->authenticity_score,
                'is_ai_generated'=>$fa->is_ai_generated,'ai_confidence'=>$fa->ai_confidence,
                'face_count'=>$fa->face_count,'tampering_indicators'=>$fa->tampering_indicators]]);
    }

    public function reanalyze(string $mediaId, Request $request): JsonResponse
    {
        $media = Image::findOrFail($mediaId);
        $opts  = $request->validate(['run_ela'=>'boolean','run_noise'=>'boolean','run_ai'=>'boolean',
            'run_copy_move'=>'boolean','run_splice'=>'boolean','run_reencoding'=>'boolean',
            'run_deepfake'=>'boolean','max_frames'=>'integer|min:5|max:120']);
        $opts['forensics_only'] = true;
        \App\Jobs\ProcessMediaJob::dispatch($media->id,$opts)->onQueue('image-processing');
        return response()->json(['message'=>ucfirst($media->media_type??'media').' re-analysis queued','media_id'=>$mediaId]);
    }

    public function anomalySummary(): JsonResponse
    {
        $iTotal = ForensicAnalysis::count(); $vTotal = VideoForensicAnalysis::count();
        return response()->json([
            'images'=>['verdicts'=>ForensicAnalysis::selectRaw('authenticity_verdict,COUNT(*) as count')->groupBy('authenticity_verdict')->get(),
                'tamper_rate'=>$iTotal>0?round(ForensicAnalysis::whereIn('authenticity_verdict',['likely_tampered','tampered'])->count()/$iTotal*100,1):0,
                'ai_rate'=>$iTotal>0?round(ForensicAnalysis::where('is_ai_generated',true)->count()/$iTotal*100,1):0,'total'=>$iTotal],
            'videos'=>['verdicts'=>VideoForensicAnalysis::selectRaw('authenticity_verdict,COUNT(*) as count')->groupBy('authenticity_verdict')->get(),
                'tamper_rate'=>$vTotal>0?round(VideoForensicAnalysis::whereIn('authenticity_verdict',['likely_tampered','tampered'])->count()/$vTotal*100,1):0,
                'deepfake_rate'=>$vTotal>0?round(VideoForensicAnalysis::where('deepfake_detected',true)->count()/$vTotal*100,1):0,'total'=>$vTotal],
        ]);
    }

    public function videoFrames(string $mediaId): JsonResponse
    {
        Image::where('media_type','video')->findOrFail($mediaId);
        $frames = VideoFrame::where('media_id',$mediaId)->where('is_suspicious',true)->orderByDesc('ela_score')->get()
            ->map(fn($f)=>['id'=>$f->id,'frame_number'=>$f->frame_number,'timestamp'=>$f->formatted_timestamp,
                'timestamp_seconds'=>$f->timestamp_seconds,'frame_type'=>$f->frame_type,
                'thumbnail_url'=>$f->thumbnail_url,'ela_score'=>$f->ela_score,'face_count'=>$f->face_count]);
        return response()->json(['media_id'=>$mediaId,'suspicious_frames'=>$frames,'count'=>$frames->count()]);
    }

    public function spliceMap(string $mediaId): JsonResponse
    {
        Image::where('media_type','video')->findOrFail($mediaId);
        $fa = VideoForensicAnalysis::where('media_id',$mediaId)->firstOrFail();
        return response()->json(['media_id'=>$mediaId,'detected'=>$fa->temporal_splicing_detected,
            'confidence'=>$fa->splicing_confidence,'splice_points'=>$fa->splice_points??[],
            'frame_count'=>VideoFrame::where('media_id',$mediaId)->count()]);
    }

    private function vColor(string $v): string {
        return match($v) {
            'authentic','likely_authentic'=>'#00e87a','suspicious'=>'#ffb400',
            'likely_tampered','tampered'=>'#ff2d55','ai_generated'=>'#9b5de5',default=>'#3a5570'
        };
    }
}
