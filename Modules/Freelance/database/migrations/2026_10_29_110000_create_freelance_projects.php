<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-201 / FL-202: a client's project (a request for freelance work) — what it is, the budget in the country's currency, how long,
 * public or private, its files (media, private disk), and every status move in `freelance_project_events` (nothing is deleted there).
 * A project belongs to the client's country (owner's decision: shown there only for now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries');
            $table->foreignId('freelance_specialty_id')->nullable()->constrained('freelance_specialties')->nullOnDelete();
            $table->string('title', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('budget_type', 10)->default('fixed')->comment('fixed | range');
            $table->unsignedBigInteger('budget_min_minor')->nullable()->comment('the amount when fixed');
            $table->unsignedBigInteger('budget_max_minor')->nullable();
            $table->string('currency_code', 10)->comment("the country's currency when the project was made");
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->string('visibility', 10)->default('public')->comment('public | private (invitations, FL-205)');
            $table->string('status', 20)->default('draft')->index();
            $table->text('status_reason')->nullable()->comment("the reviewer's reason (changes / rejection)");
            $table->unsignedInteger('version')->default(0)->comment('optimistic lock, bumped on every status move');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
            $table->index(['country_id', 'status', 'published_at']);
        });

        Schema::create('freelance_project_skills', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('freelance_skill_id')->constrained('freelance_skills')->cascadeOnDelete();
            $table->primary(['project_id', 'freelance_skill_id']);
        });

        Schema::create('freelance_project_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->string('type', 60)->comment('project.created, project.submitted, project.withdrawn…');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('actor_type', 20)->comment('client | admin | system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_project_events');
        Schema::dropIfExists('freelance_project_skills');
        Schema::dropIfExists('freelance_projects');
    }
};
