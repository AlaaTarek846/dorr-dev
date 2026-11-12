<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR AI Marketing Director, phase A (docs/marketing_tasks.md): country switches, the package and its
 * subscriptions, projects with a team, research runs with their sources and findings, plans with versions,
 * financial scenarios, approvals, jobs and the audit trail. Money is always in minor units (halalas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_feature_rules', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 40);
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['feature', 'country_id']);
        });

        Schema::create('marketing_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->json('name')->comment('{ar, en}');
            $table->json('description')->nullable()->comment('{ar, en}');
            $table->unsignedSmallInteger('period_days')->default(30);
            $table->json('limits')->nullable()->comment('per period: projects, research_runs, plan_generations, members (null = no limit)');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('marketing_package_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('marketing_packages')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor');
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->unique(['package_id', 'country_id']);
        });

        Schema::create('marketing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 40);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('package_id')->constrained('marketing_packages');
            $table->foreignId('country_id')->constrained('countries');
            $table->string('status', 20)->default('active')->comment('active | cancelled');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedBigInteger('price_minor');
            $table->string('currency_code', 8);
            $table->unsignedBigInteger('checkout_id')->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'ends_at']);
        });

        Schema::create('marketing_projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 40);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('country_id')->constrained('countries');
            $table->json('city_names')->nullable();
            $table->string('name', 120);
            $table->string('industry', 120);
            $table->string('stage', 20)->default('idea')->comment('idea | launch | growth');
            $table->json('goals')->nullable();
            $table->string('budget_currency', 8);
            $table->string('status', 20)->default('active')->comment('active | archived');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('marketing_project_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained('marketing_projects')->cascadeOnDelete();
            $table->text('offer')->nullable();
            $table->json('customer_segments')->nullable();
            $table->json('channels')->nullable();
            $table->unsignedInteger('team_size')->nullable();
            $table->unsignedBigInteger('monthly_budget_minor')->nullable()->comment('null = not given yet — never assumed');
            $table->json('assumptions')->nullable();
            $table->json('discovery')->nullable()->comment('{questions: [...], answers: {key: value}}');
            $table->timestamps();
        });

        Schema::create('marketing_project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->string('member_type', 40);
            $table->unsignedBigInteger('member_id');
            $table->string('role', 20)->comment('owner | manager | analyst | viewer');
            $table->timestamps();
            $table->unique(['project_id', 'member_type', 'member_id']);
            $table->index(['member_type', 'member_id']);
        });

        Schema::create('marketing_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->string('job_type', 30)->comment('research | plan');
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 20)->default('QUEUED');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->json('payload')->nullable()->comment('what the job was asked: locale, plan_type…');
            $table->string('requested_by_type', 40)->nullable();
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'job_type', 'created_at']);
        });

        Schema::create('marketing_research_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->string('kind', 20)->comment('market | competitor | audience');
            $table->string('status', 20)->default('QUEUED');
            $table->json('query_scope')->nullable();
            $table->unsignedTinyInteger('max_sources')->default(15);
            $table->text('summary')->nullable();
            $table->json('gaps')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable()->comment('0-100: share of findings tied to a source, scaled by coverage');
            $table->string('error_code', 60)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_run_id')->constrained('marketing_research_runs')->cascadeOnDelete();
            $table->string('ref', 8)->comment('S1, S2… — what findings cite');
            $table->string('url', 2048);
            $table->string('title', 500)->nullable();
            $table->string('publisher', 255)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retrieved_at');
            $table->text('quote_excerpt')->nullable();
            $table->string('credibility_level', 10)->default('unknown')->comment('high | medium | low | unknown');
            $table->timestamps();
            $table->unique(['research_run_id', 'ref']);
        });

        Schema::create('marketing_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_run_id')->constrained('marketing_research_runs')->cascadeOnDelete();
            $table->text('claim');
            $table->json('source_refs')->nullable();
            $table->string('confidence', 10)->default('low');
            $table->boolean('is_assumption')->default(false);
            $table->boolean('is_quantitative')->default(false);
            $table->timestamps();
        });

        Schema::create('marketing_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->string('plan_type', 20)->comment('business | marketing | gtm');
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('created_by_type', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('marketing_plans')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->json('structured_json');
            $table->string('content_hash', 64);
            $table->json('assumption_set')->nullable();
            $table->json('critic')->nullable()->comment('warnings found by the critic: [{code, detail}]');
            $table->string('origin', 10)->default('ai')->comment('ai | edit');
            $table->string('created_by_type', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'version_no']);
        });

        Schema::create('marketing_financial_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained('marketing_plan_versions')->cascadeOnDelete();
            $table->string('scenario', 20)->comment('conservative | base | optimistic');
            $table->json('inputs');
            $table->json('outputs');
            $table->string('calculation_version', 20);
            $table->timestamps();
            $table->unique(['plan_version_id', 'scenario']);
        });

        Schema::create('marketing_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('subject_version');
            $table->string('payload_hash', 64);
            $table->string('scope', 20)->default('plan')->comment('plan (phase A); spend comes with campaigns');
            $table->unsignedBigInteger('cap_minor')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('status', 20)->default('active')->comment('active | superseded | revoked | expired');
            $table->string('actor_type', 40);
            $table->unsignedBigInteger('actor_id');
            $table->timestamp('approved_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'status']);
        });

        Schema::create('marketing_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('actor_type', 40)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 60);
            $table->string('resource_type', 40)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('before_hash', 64)->nullable();
            $table->string('after_hash', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach ([
            'marketing_audit_events', 'marketing_approvals', 'marketing_financial_scenarios', 'marketing_plan_versions',
            'marketing_plans', 'marketing_findings', 'marketing_sources', 'marketing_research_runs', 'marketing_jobs',
            'marketing_project_members', 'marketing_project_profiles', 'marketing_projects', 'marketing_subscriptions',
            'marketing_package_prices', 'marketing_packages', 'marketing_feature_rules',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
