<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DORR AI WORLD, Phase 4: identity profiles (reference photos), explicit consents, and complaint/deletion reports. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_world_consents', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->string('text_version', 20);
            $table->json('scope')->nullable();
            $table->string('attestation', 40);
            $table->timestamp('granted_at');
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ai_world_identity_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->string('label', 120);
            $table->string('subject_type', 30)->default('self');
            $table->string('status', 20)->default('pending_consent')->index();
            $table->unsignedBigInteger('consent_id')->nullable();
            $table->json('reference_files')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ai_world_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('reporter_type', 20);
            $table->unsignedBigInteger('reporter_id');
            $table->string('type', 20); // complaint | deletion | impersonation
            $table->string('status', 20)->default('open')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();
        });

        Schema::table('ai_world_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('identity_profile_id')->nullable()->after('country_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_world_projects', function (Blueprint $table) {
            $table->dropColumn('identity_profile_id');
        });
        Schema::dropIfExists('ai_world_reports');
        Schema::dropIfExists('ai_world_identity_profiles');
        Schema::dropIfExists('ai_world_consents');
    }
};
