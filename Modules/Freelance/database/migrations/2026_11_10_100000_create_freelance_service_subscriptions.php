<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-L05: monthly service subscriptions. A service may be sold by the month (`billing` = monthly); ordering it opens a subscription whose
 * every month (a cycle) is an order of its own — its own project and agreement — at the price and terms frozen when it began.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_services', function (Blueprint $table) {
            $table->string('billing', 10)->default('once')->after('requirements');
        });

        Schema::create('freelance_service_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('service_id')->constrained('freelance_services')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('level', 10);
            // What each month buys, as it was when the subscription began: title, level, features, extras, decimals.
            $table->json('snapshot');
            $table->unsignedBigInteger('price_minor');
            $table->string('currency_code', 3);
            $table->unsignedSmallInteger('delivery_days');
            $table->unsignedSmallInteger('revisions')->default(0);
            $table->text('note')->nullable();
            // pending (the first month waits for the freelancer) · active · ended
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('cycle')->default(1);
            $table->timestamp('started_at')->nullable();
            // The end of the month paid now: the client confirms the next one around it.
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('renew_reminded_at')->nullable();
            // Ending with notice: who asked, when, and the day it ends.
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('cancel_by', 10)->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->timestamps();

            $table->index(['freelancer_id', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['status', 'period_ends_at']);
        });

        Schema::table('freelance_service_orders', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->after('project_id')->constrained('freelance_service_subscriptions')->nullOnDelete();
            $table->unsignedSmallInteger('cycle')->nullable()->after('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropColumn('cycle');
        });
        Schema::dropIfExists('freelance_service_subscriptions');
        Schema::table('freelance_services', function (Blueprint $table) {
            $table->dropColumn('billing');
        });
    }
};
