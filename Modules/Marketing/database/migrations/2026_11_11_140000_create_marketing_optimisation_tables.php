<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR AI Marketing Director, phase D (docs/marketing_tasks.md): A/B experiments with their measured results, and
 * alert rules on the KPIs. Nothing here changes spending: experiments and alerts only inform a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_experiments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->foreignId('content_item_id')->nullable()->constrained('marketing_content_items')->nullOnDelete();
            $table->string('name', 160);
            $table->string('hypothesis', 500);
            $table->string('success_metric', 20)->comment('ctr | conversion_rate');
            $table->json('variants')->comment('[{key, label}]');
            $table->json('results')->nullable()->comment('{A: {impressions, clicks, conversions, spend_minor}, …}');
            $table->string('status', 20)->comment('DRAFT | RUNNING | CONCLUDED');
            $table->json('conclusion')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('created_by_type', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->string('metric', 20)->comment('spend | cpa | roas | ctr | conversions');
            $table->string('comparison', 10)->comment('above | below');
            $table->decimal('threshold', 16, 4);
            $table->unsignedTinyInteger('window_days')->default(7);
            $table->string('channel', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->decimal('last_value', 16, 4)->nullable();
            $table->string('created_by_type', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_alert_rules');
        Schema::dropIfExists('marketing_experiments');
    }
};
