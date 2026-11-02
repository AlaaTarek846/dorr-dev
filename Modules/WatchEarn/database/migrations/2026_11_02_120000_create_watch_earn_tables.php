<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watch & Earn (docs/watch_earn_tasks.md). Money is in minor units, like the wallet. Every amount an advertiser holds
 * remembers which wallet bucket it came from (reward / spend_only / withdrawable) so it goes back to the same one (D12,
 * D13): the ad wallet's free balance by bucket, and each ad's reserved budget by bucket (D14).
 */
return new class extends Migration
{
    public function up(): void
    {
        // WE-001 — one row per country; every price, limit and wait is here, never hard-coded.
        Schema::create('we_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->unique()->constrained('countries')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('price_per_view_minor')->default(0)->comment('What a completed view costs the advertiser');
            $table->unsignedBigInteger('platform_share_minor')->default(0);
            $table->unsignedBigInteger('view_reward_minor')->default(0);
            $table->unsignedBigInteger('interaction_reward_minor')->default(0);
            $table->string('unclaimed_interaction', 12)->default('platform')->comment('No interaction: platform (it keeps that share) | not_charged (the advertiser is not charged it)');
            $table->string('viewing_mode', 8)->default('auto')->comment('auto | manual (D6)');
            $table->unsignedSmallInteger('image_seconds')->default(20);
            $table->unsignedSmallInteger('video_max_seconds')->default(60);
            $table->unsignedSmallInteger('video_max_mb')->default(60);
            $table->unsignedBigInteger('min_ad_wallet_minor')->default(0)->comment('Needed in the ad wallet to send an ad');
            $table->unsignedTinyInteger('low_balance_percent')->default(20)->comment('Warn the advertiser when an ad has this much of its budget left');
            $table->unsignedSmallInteger('report_threshold')->default(10)->comment('Unique reports that suspend an ad (0 = off)');
            $table->unsignedSmallInteger('interaction_window_minutes')->default(10);
            $table->unsignedSmallInteger('view_reward_wait_hours')->default(0);
            $table->unsignedSmallInteger('interaction_reward_wait_hours')->default(0);
            $table->timestamps();
        });

        // WE-011 — the free balance by the bucket it came from. Reserved money lives on each ad.
        Schema::create('we_ad_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->unsignedBigInteger('reward_minor')->default(0);
            $table->unsignedBigInteger('spend_only_minor')->default(0);
            $table->unsignedBigInteger('withdrawable_minor')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'country_id']);
        });

        Schema::create('we_ads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ad_wallet_id')->constrained('we_ad_wallets')->restrictOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->string('title', 120);
            $table->text('body')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('media_type', 8)->comment('image | video');
            $table->string('media_path', 255)->nullable();
            $table->string('media_mime', 60)->nullable();
            $table->unsignedBigInteger('media_size')->nullable();
            $table->unsignedSmallInteger('media_seconds')->nullable()->comment('How long it shows: the video length, or the image time at submission');
            $table->string('thumb_path', 255)->nullable();
            $table->unsignedInteger('media_version')->default(1)->comment('Bumped by a content edit (re-review); who saw it stays (REQ-005)');
            $table->string('target_gender', 10)->nullable()->comment('null = everyone');
            $table->string('target_locale', 5)->nullable()->comment('null = every language');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // The price when it was sent — a later change in the settings is for new ads (fair to the advertiser).
            $table->unsignedBigInteger('price_per_view_minor');
            $table->unsignedBigInteger('platform_share_minor');
            $table->unsignedBigInteger('view_reward_minor');
            $table->unsignedBigInteger('interaction_reward_minor');
            $table->string('unclaimed_interaction', 12);
            // The budget: everything ever reserved, what's spent, and what's still reserved by bucket.
            $table->unsignedBigInteger('budget_minor')->default(0);
            $table->unsignedBigInteger('spent_minor')->default(0);
            $table->unsignedBigInteger('reserved_reward_minor')->default(0);
            $table->unsignedBigInteger('reserved_spend_only_minor')->default(0);
            $table->unsignedBigInteger('reserved_withdrawable_minor')->default(0);
            // Counters for the stats page (the details are in we_views).
            $table->unsignedInteger('impressions_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('interactions_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('reactions_count')->default(0);
            $table->unsignedInteger('reports_count')->default(0);
            $table->string('reject_reason', 500)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('suspended_at')->nullable()->comment('By reports or the admin — the end date moves on by as long when it comes back (REQ-018)');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('low_balance_notified_at')->nullable();
            $table->timestamps();

            $table->index(['country_id', 'status', 'starts_at', 'ends_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('we_ad_cities', function (Blueprint $table) {
            $table->foreignId('ad_id')->constrained('we_ads')->cascadeOnDelete();
            $table->unsignedBigInteger('city_id')->comment('discover_cities.id');
            $table->primary(['ad_id', 'city_id']);
            $table->index('city_id');
        });

        // The ad wallet's book: every move in or out of it, by bucket.
        Schema::create('we_ad_wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_wallet_id')->constrained('we_ad_wallets')->cascadeOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained('we_ads')->nullOnDelete();
            $table->string('type', 20)->comment('fund | topup | return | reserve | release');
            $table->unsignedBigInteger('reward_minor')->default(0);
            $table->unsignedBigInteger('spend_only_minor')->default(0);
            $table->unsignedBigInteger('withdrawable_minor')->default(0);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('free_after_minor')->comment('The free balance after this entry');
            $table->string('operation_id', 64)->nullable()->comment('The wallet operation it goes with');
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ad_wallet_id', 'id']);
        });

        // One row per viewer and ad: started → completed (charged once) → maybe interacted (charged once).
        Schema::create('we_views', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ad_id')->constrained('we_ads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('media_version');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('platform', 20)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('interaction_deadline_at')->nullable();
            $table->timestamp('interacted_at')->nullable();
            $table->string('interaction_type', 10)->nullable()->comment('comment | reaction');
            $table->unsignedBigInteger('charged_minor')->default(0);
            // The interaction share is charged with the view and held here, by bucket, until the viewer interacts
            // (→ their reward) or the window closes (→ the platform, or back to the ad's budget: `unclaimed_interaction`).
            $table->json('interaction_hold')->nullable();
            $table->timestamp('interaction_settled_at')->nullable();
            $table->timestamps();

            $table->unique(['ad_id', 'user_id']);
            $table->index(['interaction_settled_at', 'interaction_deadline_at']);
            $table->index(['ad_id', 'completed_at']);
        });

        Schema::create('we_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ad_id')->constrained('we_ads')->cascadeOnDelete();
            $table->foreignId('view_id')->constrained('we_views')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('kind', 12)->comment('view | interaction');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('available_at');
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->timestamps();

            $table->unique(['view_id', 'kind']);
            $table->index(['released_at', 'available_at']);
            $table->index(['user_id', 'id']);
        });

        Schema::create('we_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained('we_ads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 1000);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ad_id', 'id']);
        });

        Schema::create('we_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained('we_ads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['ad_id', 'user_id']);
        });

        Schema::create('we_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained('we_ads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 30);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['ad_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['we_reports', 'we_reactions', 'we_comments', 'we_rewards', 'we_views', 'we_ad_wallet_entries', 'we_ad_cities', 'we_ads', 'we_ad_wallets', 'we_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
