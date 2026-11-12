<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR AI Marketing Director, phase B (docs/marketing_tasks.md): the daily calendar and the ad studio (content items
 * and tasks), budgets (ad spend in money, AI in calls — kept apart, spec 09), results entered by hand or from a file,
 * and the marketing AI calls each project made. Money is in minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_content_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('marketing_plans')->nullOnDelete();
            $table->string('kind', 10)->comment('post | ad | task');
            $table->string('channel', 20);
            $table->string('format', 20)->nullable()->comment('text | image | video | story | reel | carousel');
            $table->string('title', 160);
            $table->string('objective', 300)->nullable();
            $table->json('content')->nullable()->comment('{variants: [{key, headline, body, cta, hashtags, visual_brief}], selected}');
            $table->json('claims')->nullable()->comment('claims review: [{code, severity, variant, detail}]');
            $table->string('status', 20)->comment('DRAFT | POLICY_REVIEW | APPROVED | SCHEDULED | PUBLISHED | FAILED | REJECTED; tasks: OPEN | DONE');
            $table->timestamp('planned_at')->nullable()->comment('when it should go out (or the task is due)');
            $table->unsignedBigInteger('cost_estimate_minor')->nullable()->comment('the person\'s own estimate, never the AI\'s');
            $table->string('origin', 10)->default('manual')->comment('calendar | studio | manual');
            $table->string('review_note', 500)->nullable();
            $table->string('published_url', 500)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('created_by_type', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'planned_at']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('marketing_budgets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->string('kind', 10)->comment('ads (money) | ai (calls)');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 8)->nullable();
            $table->unsignedBigInteger('cap_minor')->nullable()->comment('ads');
            $table->unsignedInteger('cap_calls')->nullable()->comment('ai');
            $table->unsignedTinyInteger('alert_pct')->default(80);
            $table->string('approval_status', 20)->comment('PENDING | APPROVED | CLOSED');
            $table->string('last_alert_level', 10)->nullable()->comment('warning | exceeded — the last level the team was told about');
            $table->string('note', 300)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('created_by_type', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('approved_by_type', 40)->nullable();
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'kind', 'period_start']);
        });

        Schema::create('marketing_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->foreignId('content_item_id')->nullable()->constrained('marketing_content_items')->nullOnDelete();
            $table->unsignedBigInteger('campaign_id')->nullable()->comment('phase C');
            $table->string('channel', 20);
            $table->date('metric_date');
            $table->string('source', 10)->comment('manual | csv | platform');
            $table->string('basis', 10)->comment('imported | estimated | actual');
            $table->string('row_key', 120)->comment('one row per project/channel/day/basis/content: a second import updates it');
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('spend_minor')->nullable();
            $table->unsignedBigInteger('conversions')->nullable();
            $table->unsignedBigInteger('revenue_minor')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('note', 300)->nullable();
            $table->uuid('import_batch')->nullable();
            $table->string('entered_by_type', 40)->nullable();
            $table->unsignedBigInteger('entered_by_id')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'row_key']);
            $table->index(['project_id', 'metric_date']);
        });

        Schema::create('marketing_ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->string('source', 60);
            $table->unsignedInteger('input_chars')->default(0);
            $table->unsignedInteger('output_chars')->default(0);
            $table->string('actor_type', 40)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_ai_calls');
        Schema::dropIfExists('marketing_metrics');
        Schema::dropIfExists('marketing_budgets');
        Schema::dropIfExists('marketing_content_items');
    }
};
