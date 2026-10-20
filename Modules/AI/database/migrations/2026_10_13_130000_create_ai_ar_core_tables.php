<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DORR AR Core Engine, Phase 1: settings, country switches, 3D assets (versioned), experiences (versioned scenes), captures, device profiles. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_ar_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('max_asset_mb')->default(15);
            $table->unsignedInteger('max_triangles')->default(150000);
            $table->unsignedSmallInteger('capture_retention_days')->default(30);
            $table->timestamps();
        });

        Schema::create('ai_ar_country_modes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id')->index();
            $table->string('specialty', 30);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'specialty']);
        });

        Schema::create('ai_ar_assets', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name_ar', 191)->nullable();
            $table->string('name_en', 191);
            $table->string('category', 40)->index();
            $table->string('source', 20)->default('library'); // library | uploaded | generated
            $table->string('license', 20)->nullable();         // own | cc0 | cc_by | purchased | generated
            $table->string('license_note', 255)->nullable();
            $table->string('attribution', 255)->nullable();
            $table->string('owner_type', 20)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('status', 20)->default('draft')->index(); // draft | active | rejected | archived
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('is_public')->default(false)->index();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ai_ar_asset_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('ai_ar_assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->string('glb_path');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('triangle_count')->default(0);
            $table->json('real_dimensions')->nullable(); // width/height/depth in metres, from the model's own bounds
            $table->boolean('optimized')->default(false);
            $table->string('checksum', 64);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'version_no']);
        });

        Schema::create('ai_ar_experiences', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('type', 20)->default('free'); // decor | education | commerce | real_estate | maintenance | portal | free
            $table->string('title', 191);
            $table->string('status', 20)->default('draft')->index();
            $table->string('source_service', 60)->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ai_ar_experience_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_id')->constrained('ai_ar_experiences')->cascadeOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['experience_id', 'version_no']);
        });

        Schema::create('ai_ar_scene_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_version_id')->constrained('ai_ar_experience_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('asset_version_id');
            $table->json('transform'); // position [x,y,z] metres, rotation quaternion [x,y,z,w], scale [x,y,z]
            $table->string('tint', 9)->nullable();
            $table->boolean('visible')->default(true);
            $table->string('anchor_type', 10)->default('plane'); // plane | image | world
            $table->string('label', 120)->nullable();
            $table->json('info')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_ar_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_id')->constrained('ai_ar_experiences')->cascadeOnDelete();
            $table->string('kind', 10); // photo | video
            $table->string('file_path');
            $table->boolean('consent_recorded')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_ar_device_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10)->default('android');
            $table->string('manufacturer', 60);
            $table->string('model_pattern', 120); // SQL LIKE pattern, e.g. "SM-S91%"
            $table->boolean('arcore_supported')->default(true);
            $table->boolean('depth_supported')->default(false);
            $table->string('tier', 20)->default('basic'); // full | basic | fallback_only
            $table->string('notes', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['device_profiles', 'captures', 'scene_items', 'experience_versions', 'experiences', 'asset_versions', 'assets', 'country_modes', 'settings'] as $t) {
            Schema::dropIfExists('ai_ar_'.$t);
        }
    }
};
