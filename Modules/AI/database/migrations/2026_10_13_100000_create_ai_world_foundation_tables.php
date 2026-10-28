<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR AI WORLD, Phase 1 foundation: service settings, per-country modes and the location catalog
 * (curated landmarks + places cached from the maps provider, each classified by fidelity tier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_world_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('max_scenes_per_project')->default(5);
            $table->unsignedSmallInteger('max_retries_per_scene')->default(2);
            // Starting value only: calibrated from the feasibility spike before it is relied upon.
            $table->decimal('identity_threshold', 5, 2)->default(80);
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->unsignedBigInteger('default_provider_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_world_country_modes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id')->index();
            $table->string('mode', 30);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'mode']);
        });

        Schema::create('ai_world_locations', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->default('curated');
            $table->string('provider_place_id', 191);
            $table->string('name_en', 191);
            $table->string('name_ar', 191)->nullable();
            $table->string('kind', 20)->default('landmark');
            $table->char('country_code', 2)->nullable()->index();
            $table->string('city', 120)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('formatted_address', 255)->nullable();
            $table->text('search_text');
            $table->boolean('is_curated')->default(false)->index();
            $table->boolean('tiles_available')->default(false);
            $table->string('fidelity_tier', 30)->default('cinematic');
            $table->string('tier_reason', 40)->nullable();
            $table->json('references')->nullable();
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'provider_place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_world_locations');
        Schema::dropIfExists('ai_world_country_modes');
        Schema::dropIfExists('ai_world_settings');
    }
};
