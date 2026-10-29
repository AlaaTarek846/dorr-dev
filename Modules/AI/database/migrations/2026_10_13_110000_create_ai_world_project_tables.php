<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR AI WORLD, Phase 2: projects (a trip video), scenes (one generated clip each), scene versions
 * (every attempt, kept for audit and regeneration), the per-country price table and the cost ledger.
 * Money is stored in minor units of the owner's country currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_world_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id')->index();
            $table->string('quality', 20)->default('standard');
            $table->unsignedInteger('price_per_second_minor');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'quality']);
        });

        Schema::create('ai_world_projects', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('title', 191)->nullable();
            $table->string('mode', 30)->default('single');
            $table->string('status', 30)->default('draft')->index();
            $table->string('quality', 20)->default('standard');
            $table->text('brief')->nullable();
            $table->json('reference_files')->nullable();
            $table->unsignedInteger('total_seconds')->default(0);
            $table->unsignedBigInteger('estimated_minor')->default(0);
            $table->unsignedBigInteger('charged_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->string('currency', 8)->nullable();
            $table->string('charge_operation_id', 100)->nullable();
            $table->string('final_video_path')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id', 'created_at']);
        });

        Schema::create('ai_world_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('ai_world_projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->text('prompt');
            $table->unsignedTinyInteger('seconds')->default(8);
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'position']);
        });

        Schema::create('ai_world_scene_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained('ai_world_scenes')->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->default(1);
            $table->unsignedBigInteger('media_generation_id')->nullable();
            $table->string('status', 30)->default('queued');
            $table->string('video_path')->nullable();
            $table->decimal('identity_score', 5, 2)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->unique(['scene_id', 'number']);
        });

        Schema::create('ai_world_cost_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('ai_world_projects')->cascadeOnDelete();
            $table->unsignedBigInteger('scene_id')->nullable();
            $table->string('type', 20); // charge | refund
            $table->bigInteger('amount_minor');
            $table->string('currency', 8)->nullable();
            $table->string('operation_id', 100)->nullable();
            $table->string('note', 191)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_world_cost_ledger');
        Schema::dropIfExists('ai_world_scene_versions');
        Schema::dropIfExists('ai_world_scenes');
        Schema::dropIfExists('ai_world_projects');
        Schema::dropIfExists('ai_world_prices');
    }
};
