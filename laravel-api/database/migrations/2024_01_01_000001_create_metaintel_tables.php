<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Images ──────────────────────────────────────────────────────────────
        Schema::create('images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('storage_path');
            $table->string('storage_disk')->default('s3');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size'); // bytes
            $table->string('sha256_hash', 64)->unique()->index();
            $table->string('md5_hash', 32)->index();
            $table->string('phash', 64)->nullable(); // perceptual hash
            $table->enum('status', [
                'pending', 'queued', 'processing', 'completed', 'failed', 'sanitized'
            ])->default('pending')->index();
            $table->enum('upload_source', ['web', 'api', 'batch', 'webhook'])->default('web');
            $table->string('upload_ip', 45)->nullable();
            $table->string('upload_user_agent')->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('batch_jobs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['mime_type', 'created_at']);
        });

        // ─── Batch Jobs ───────────────────────────────────────────────────────────
        Schema::create('batch_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('uuid')->unique()->index();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->unsignedInteger('total_images')->default(0);
            $table->unsignedInteger('processed_images')->default(0);
            $table->unsignedInteger('failed_images')->default(0);
            $table->json('options')->nullable(); // processing options
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // ─── Metadata Records ─────────────────────────────────────────────────────
        Schema::create('metadata_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('image_id')->index();
            $table->foreign('image_id')->references('id')->on('images')->cascadeOnDelete();

            // Core image dimensions
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('color_space')->nullable();
            $table->unsignedTinyInteger('bit_depth')->nullable();

            // Camera & Device
            $table->string('camera_make')->nullable()->index();
            $table->string('camera_model')->nullable()->index();
            $table->string('lens_model')->nullable();
            $table->string('software')->nullable()->index();
            $table->string('firmware_version')->nullable();
            $table->string('serial_number')->nullable();

            // Capture settings
            $table->string('exposure_time')->nullable();
            $table->string('f_number')->nullable();
            $table->string('iso_speed')->nullable();
            $table->string('focal_length')->nullable();
            $table->string('focal_length_35mm')->nullable();
            $table->string('flash')->nullable();
            $table->string('white_balance')->nullable();
            $table->string('metering_mode')->nullable();
            $table->string('exposure_mode')->nullable();
            $table->string('scene_capture_type')->nullable();

            // Timestamps
            $table->timestamp('date_time_original')->nullable()->index();
            $table->timestamp('date_time_digitized')->nullable();
            $table->timestamp('date_time_modified')->nullable();
            $table->string('timezone_offset')->nullable();
            $table->string('sub_sec_time')->nullable();

            // GPS (duplicated for fast querying, full data in gps_data)
            $table->decimal('gps_latitude', 10, 7)->nullable();
            $table->decimal('gps_longitude', 10, 7)->nullable();
            $table->decimal('gps_altitude', 8, 2)->nullable();

            // Copyright & Creator (IPTC/XMP)
            $table->string('copyright')->nullable();
            $table->string('creator')->nullable();
            $table->string('credit')->nullable();
            $table->string('source')->nullable();
            $table->text('caption')->nullable();
            $table->string('headline')->nullable();
            $table->json('keywords')->nullable();
            $table->string('subject_code')->nullable();

            // Color profile
            $table->string('icc_profile_name')->nullable();
            $table->string('color_profile_description')->nullable();
            $table->string('rendering_intent')->nullable();

            // Metadata standards detected
            $table->boolean('has_exif')->default(false);
            $table->boolean('has_iptc')->default(false);
            $table->boolean('has_xmp')->default(false);
            $table->boolean('has_icc')->default(false);
            $table->boolean('has_maker_notes')->default(false);

            // Full raw dumps
            $table->jsonb('raw_exif')->nullable();
            $table->jsonb('raw_iptc')->nullable();
            $table->jsonb('raw_xmp')->nullable();
            $table->jsonb('raw_maker_notes')->nullable();
            $table->jsonb('raw_icc')->nullable();

            // Anomaly flags
            $table->json('anomaly_flags')->nullable();
            $table->unsignedTinyInteger('anomaly_count')->default(0)->index();

            $table->timestamps();
        });

        // ─── GPS Data ─────────────────────────────────────────────────────────────
        Schema::create('gps_data', function (Blueprint $table) {
            $table->id();
            $table->uuid('image_id');
            $table->foreign('image_id')->references('id')->on('images')->cascadeOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('altitude', 8, 2)->nullable();
            $table->string('altitude_ref')->nullable(); // 0=above sea, 1=below
            $table->string('direction')->nullable(); // compass direction
            $table->string('direction_ref')->nullable();
            $table->string('speed')->nullable();
            $table->string('speed_ref')->nullable();
            $table->string('gps_timestamp')->nullable();
            $table->string('gps_datestamp')->nullable();
            $table->string('map_datum')->nullable(); // WGS-84
            $table->string('dop')->nullable(); // dilution of precision
            $table->string('measure_mode')->nullable();
            $table->string('satellites')->nullable();
            $table->string('status')->nullable();
            $table->string('processing_method')->nullable();

            // Reverse geocoded
            $table->string('country_code', 2)->nullable()->index();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('place_name')->nullable();
            $table->json('full_address_components')->nullable();

            // PostGIS geometry (if PostGIS is available)
            // $table->point('geom')->nullable();

            $table->timestamps();
            $table->index(['latitude', 'longitude']);
            $table->index(['country_code', 'city']);
        });

        // ─── Forensic Analysis Results ────────────────────────────────────────────
        Schema::create('forensic_analysis', function (Blueprint $table) {
            $table->id();
            $table->uuid('image_id');
            $table->foreign('image_id')->references('id')->on('images')->cascadeOnDelete();

            // Error Level Analysis
            $table->boolean('ela_performed')->default(false);
            $table->decimal('ela_score', 5, 2)->nullable(); // 0-100
            $table->string('ela_image_path')->nullable();
            $table->json('ela_regions')->nullable(); // suspicious regions

            // Noise Analysis
            $table->boolean('noise_analysis_performed')->default(false);
            $table->decimal('noise_score', 5, 2)->nullable();
            $table->json('noise_map')->nullable();

            // Copy-Move Detection
            $table->boolean('copy_move_detected')->nullable();
            $table->decimal('copy_move_confidence', 5, 2)->nullable();
            $table->json('copy_move_regions')->nullable();

            // Splicing Detection
            $table->boolean('splicing_detected')->nullable();
            $table->decimal('splicing_confidence', 5, 2)->nullable();

            // AI/Deepfake Detection
            $table->boolean('ai_detection_performed')->default(false);
            $table->boolean('is_ai_generated')->nullable()->index();
            $table->decimal('ai_confidence', 5, 2)->nullable();
            $table->string('ai_model_used')->nullable();
            $table->json('ai_detection_details')->nullable();

            // Overall Authenticity
            $table->enum('authenticity_verdict', [
                'authentic', 'likely_authentic', 'suspicious', 'likely_tampered', 'tampered', 'ai_generated', 'unknown'
            ])->nullable()->index();
            $table->decimal('authenticity_score', 5, 2)->nullable(); // 0-100
            $table->json('tampering_indicators')->nullable();

            // PRNU Camera Fingerprint
            $table->string('prnu_camera_fingerprint')->nullable();
            $table->decimal('prnu_correlation', 5, 4)->nullable();
            $table->boolean('prnu_match')->nullable();

            // Steganography
            $table->boolean('steg_analysis_performed')->default(false);
            $table->boolean('steg_detected')->nullable();
            $table->decimal('steg_confidence', 5, 2)->nullable();

            // Face Detection
            $table->json('faces_detected')->nullable();
            $table->unsignedTinyInteger('face_count')->default(0);

            // Object Detection
            $table->json('objects_detected')->nullable();

            // Processing info
            $table->string('analysis_version')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->unsignedSmallInteger('processing_time_ms')->nullable();

            $table->timestamps();
        });

        // ─── Timeline Events ──────────────────────────────────────────────────────
        Schema::create('timeline_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('image_id');
            $table->foreign('image_id')->references('id')->on('images')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batch_jobs')->nullOnDelete();

            $table->string('event_type'); // capture, edit, upload, location, etc.
            $table->string('event_source'); // exif, iptc, xmp, filesystem, analysis
            $table->timestamp('event_time')->index();
            $table->string('timezone')->nullable();
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->boolean('is_anomaly')->default(false)->index();
            $table->string('anomaly_reason')->nullable();

            $table->timestamps();
            $table->index(['batch_id', 'event_time']);
        });

        // ─── API Keys ─────────────────────────────────────────────────────────────
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key', 64)->unique()->index();
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('request_count')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // ─── Webhooks ─────────────────────────────────────────────────────────────
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->json('events'); // which events to subscribe to
            $table->string('secret', 64);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('retry_count')->default(3);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });

        // ─── Forensic Reports ─────────────────────────────────────────────────────
        Schema::create('forensic_reports', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->enum('report_type', ['single', 'batch', 'investigation'])->default('single');
            $table->json('image_ids');
            $table->json('sections'); // which sections to include
            $table->enum('format', ['pdf', 'json', 'csv', 'html'])->default('pdf');
            $table->string('file_path')->nullable();
            $table->enum('status', ['pending', 'generating', 'completed', 'failed'])->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // ─── Elasticsearch Sync Log ───────────────────────────────────────────────
        Schema::create('search_index_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('image_id')->index();
            $table->string('operation'); // indexed, updated, deleted
            $table->boolean('success')->default(true);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index_log');
        Schema::dropIfExists('forensic_reports');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('timeline_events');
        Schema::dropIfExists('forensic_analysis');
        Schema::dropIfExists('gps_data');
        Schema::dropIfExists('metadata_records');
        Schema::dropIfExists('images');
        Schema::dropIfExists('batch_jobs');
    }
};
