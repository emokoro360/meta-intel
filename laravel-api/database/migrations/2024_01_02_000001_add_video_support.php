<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Extend media table to unify images + videos ─────────────────────
        // Rename 'images' to 'media' conceptually; add type discriminator
        Schema::table('images', function (Blueprint $table) {
            $table->enum('media_type', ['image', 'video'])->default('image')->after('id')->index();
        });

        // ─── Video Metadata ───────────────────────────────────────────────────
        Schema::create('video_metadata', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_id')->index();
            $table->foreign('media_id')->references('id')->on('images')->cascadeOnDelete();

            // Container / Format
            $table->string('container_format')->nullable();       // mp4, mov, avi, mkv…
            $table->string('format_long_name')->nullable();
            $table->decimal('duration_seconds', 10, 3)->nullable()->index();
            $table->unsignedBigInteger('bitrate_bps')->nullable(); // overall bitrate
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->unsignedInteger('nb_streams')->nullable();
            $table->string('format_tags')->nullable();            // raw JSON string of container tags

            // Video stream
            $table->string('video_codec')->nullable()->index();   // h264, h265, vp9, av1…
            $table->string('video_codec_long')->nullable();
            $table->string('video_profile')->nullable();          // High, Main, Baseline…
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('display_aspect_ratio')->nullable();   // 16:9, 4:3…
            $table->string('pix_fmt')->nullable();                // yuv420p, yuv422p…
            $table->string('frame_rate')->nullable();             // "30/1", "29.97"
            $table->decimal('avg_frame_rate', 8, 4)->nullable();
            $table->unsignedBigInteger('nb_frames')->nullable();
            $table->unsignedBigInteger('video_bitrate_bps')->nullable();
            $table->string('color_space')->nullable();
            $table->string('color_transfer')->nullable();         // bt709, smpte2084 (HDR)…
            $table->string('color_primaries')->nullable();
            $table->boolean('is_hdr')->default(false);
            $table->string('rotation')->nullable();               // 0, 90, 180, 270

            // Audio streams (one or more tracks)
            $table->json('audio_streams')->nullable();            // [{codec, channels, sample_rate, bitrate, language}]
            $table->unsignedTinyInteger('audio_track_count')->default(0);

            // Subtitle / chapter streams
            $table->json('subtitle_streams')->nullable();
            $table->json('chapters')->nullable();

            // Encoding metadata
            $table->string('encoder')->nullable();               // HandBrake, FFmpeg, Adobe…
            $table->timestamp('creation_time')->nullable()->index();
            $table->string('creation_timezone')->nullable();
            $table->string('make')->nullable();                  // Device that shot the video
            $table->string('model')->nullable();
            $table->string('software')->nullable();
            $table->string('handler_name')->nullable();

            // GPS track (mobile videos embed GPS in atoms)
            $table->decimal('gps_latitude', 10, 7)->nullable();
            $table->decimal('gps_longitude', 10, 7)->nullable();
            $table->decimal('gps_altitude', 8, 2)->nullable();
            $table->json('gps_track')->nullable();               // [{t, lat, lon, alt}] from GPS box

            // Content summary
            $table->unsignedSmallInteger('scene_count')->nullable();
            $table->unsignedSmallInteger('keyframe_count')->nullable();
            $table->decimal('average_scene_length_s', 6, 2)->nullable();

            // Raw metadata dumps
            $table->jsonb('raw_ffprobe')->nullable();
            $table->jsonb('raw_mediainfo')->nullable();
            $table->json('raw_container_tags')->nullable();
            $table->json('raw_video_tags')->nullable();
            $table->json('raw_audio_tags')->nullable();

            // Anomaly flags
            $table->json('anomaly_flags')->nullable();
            $table->unsignedTinyInteger('anomaly_count')->default(0);

            $table->timestamps();
        });

        // ─── Video Frames (keyframes extracted for forensics) ─────────────────
        Schema::create('video_frames', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_id')->index();
            $table->foreign('media_id')->references('id')->on('images')->cascadeOnDelete();

            $table->decimal('timestamp_seconds', 10, 3)->index();  // when in video
            $table->unsignedInteger('frame_number');
            $table->string('storage_path');                         // S3 path of extracted frame
            $table->enum('frame_type', ['keyframe', 'sample', 'scene_change', 'suspicious'])->default('keyframe');

            // Per-frame forensics
            $table->decimal('ela_score', 5, 2)->nullable();
            $table->decimal('noise_score', 5, 2)->nullable();
            $table->boolean('is_suspicious')->default(false)->index();
            $table->json('forensic_details')->nullable();

            // Face / object detection per frame
            $table->json('faces_detected')->nullable();
            $table->unsignedTinyInteger('face_count')->default(0);
            $table->json('objects_detected')->nullable();

            $table->timestamps();
            $table->index(['media_id', 'timestamp_seconds']);
            $table->index(['media_id', 'is_suspicious']);
        });

        // ─── Video Forensic Analysis ──────────────────────────────────────────
        Schema::create('video_forensic_analysis', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_id');
            $table->foreign('media_id')->references('id')->on('images')->cascadeOnDelete();

            // Re-encoding detection
            $table->boolean('reencoding_detected')->nullable();
            $table->decimal('reencoding_confidence', 5, 2)->nullable();
            $table->json('reencoding_evidence')->nullable();       // macroblock patterns, GOP structure

            // Splicing / cut detection
            $table->boolean('temporal_splicing_detected')->nullable();
            $table->decimal('splicing_confidence', 5, 2)->nullable();
            $table->json('splice_points')->nullable();             // [{timestamp, confidence}]

            // Audio-video sync analysis
            $table->boolean('av_sync_anomaly')->nullable();
            $table->decimal('av_sync_offset_ms', 8, 2)->nullable();

            // Deepfake / AI-generated face detection (video-specific)
            $table->boolean('deepfake_detected')->nullable();
            $table->decimal('deepfake_confidence', 5, 2)->nullable();
            $table->string('deepfake_model_used')->nullable();
            $table->json('deepfake_frame_scores')->nullable();     // per-frame scores

            // Frame-level ELA summary
            $table->decimal('avg_ela_score', 5, 2)->nullable();
            $table->decimal('max_ela_score', 5, 2)->nullable();
            $table->unsignedSmallInteger('suspicious_frame_count')->default(0);

            // Codec fingerprint anomalies
            $table->boolean('codec_mismatch_detected')->nullable();
            $table->json('codec_anomalies')->nullable();

            // Overall verdict
            $table->enum('authenticity_verdict', [
                'authentic', 'likely_authentic', 'suspicious',
                'likely_tampered', 'tampered', 'ai_generated', 'unknown'
            ])->nullable()->index();
            $table->decimal('authenticity_score', 5, 2)->nullable();
            $table->json('tampering_indicators')->nullable();

            $table->unsignedSmallInteger('frames_analyzed')->default(0);
            $table->string('analysis_version')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->unsignedInteger('processing_time_ms')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_forensic_analysis');
        Schema::dropIfExists('video_frames');
        Schema::dropIfExists('video_metadata');
        Schema::table('images', function (Blueprint $table) {
            $table->dropColumn('media_type');
        });
    }
};
