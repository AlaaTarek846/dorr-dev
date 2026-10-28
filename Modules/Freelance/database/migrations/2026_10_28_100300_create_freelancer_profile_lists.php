<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-104: what a freelancer puts on their profile besides the basics — the specialties they work in (one primary), the
 * skills they list (with a level), their work history and their certificates (a certificate's proof file is a private
 * media file). A specialty or a skill in use can't be deleted from the catalogs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelancer_specialties', function (Blueprint $table) {
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('freelance_specialty_id')->constrained('freelance_specialties')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->primary(['freelancer_id', 'freelance_specialty_id'], 'freelancer_specialty_primary');
        });

        Schema::create('freelancer_skills', function (Blueprint $table) {
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('freelance_skill_id')->constrained('freelance_skills')->cascadeOnDelete();
            $table->string('level', 12)->nullable()->comment('beginner / intermediate / advanced / expert');
            $table->timestamp('created_at')->nullable();
            $table->primary(['freelancer_id', 'freelance_skill_id'], 'freelancer_skill_primary');
            $table->index('freelance_skill_id');
        });

        Schema::create('freelancer_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('company', 120)->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('description', 1000)->nullable();
            $table->timestamps();
            $table->index(['freelancer_id', 'started_on']);
        });

        Schema::create('freelancer_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('issuer', 150);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('credential_url', 255)->nullable();
            $table->timestamps();
            $table->index('freelancer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelancer_certificates');
        Schema::dropIfExists('freelancer_experiences');
        Schema::dropIfExists('freelancer_skills');
        Schema::dropIfExists('freelancer_specialties');
    }
};
