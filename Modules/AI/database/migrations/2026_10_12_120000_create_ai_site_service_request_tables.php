<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Build it with a person": a customer briefs the team, the admin answers with a quote (price, duration, deliverable
 * schedule, payment instalments), and once accepted the work runs with a countdown and instalments paid from the wallet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_site_service_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('quote_valid_days')->default(7);
            $table->text('intro_ar')->nullable();
            $table->text('intro_en')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_site_request_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_ar', 80);
            $table->string('name_en', 80);
            $table->string('color', 20)->default('secondary');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_site_service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('title', 150);
            $table->string('site_type', 40)->nullable();
            $table->string('status', 40)->default('submitted')->index();
            $table->json('brief');
            $table->json('files')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('currency', 10)->nullable();

            $table->decimal('quote_amount', 14, 2)->nullable();
            $table->unsignedSmallInteger('quote_duration_days')->nullable();
            $table->dateTime('quote_valid_until')->nullable();
            $table->text('quote_notes')->nullable();
            $table->dateTime('quote_sent_at')->nullable();
            $table->text('decline_reason')->nullable();

            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->text('admin_notes')->nullable();
            $table->string('delivery_url', 500)->nullable();
            $table->text('delivery_notes')->nullable();
            $table->dateTime('admin_seen_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ai_site_request_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('ai_site_service_requests')->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('due_days');
            $table->dateTime('due_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->dateTime('delivered_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_site_request_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('ai_site_service_requests')->cascadeOnDelete();
            $table->string('title', 150);
            $table->decimal('amount', 14, 2);
            $table->string('trigger', 20)->default('on_accept');
            $table->foreignId('milestone_id')->nullable()->constrained('ai_site_request_milestones')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->string('wallet_operation_id', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_site_request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('ai_site_service_requests')->cascadeOnDelete();
            $table->string('sender', 10);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('body');
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_site_request_messages');
        Schema::dropIfExists('ai_site_request_installments');
        Schema::dropIfExists('ai_site_request_milestones');
        Schema::dropIfExists('ai_site_service_requests');
        Schema::dropIfExists('ai_site_request_statuses');
        Schema::dropIfExists('ai_site_service_settings');
    }
};
