<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DORR points for riders (docs/rides_tasks.md R-146..R-155): points for every paid ride / booked service,
 * tiers by the points of the last `loyalty.tier_window_days`, badges and weekly / seasonal challenges set
 * by the admin, and rewards to spend the points on (wallet credit or a coupon). Every movement of points is
 * one append-only row in `loyalty_entries`; the balance on the account is the sum of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->json('name')->comment('{ar, en}');
            $table->json('benefits')->nullable()->comment('{ar: [...], en: [...]} — what the tier gives');
            $table->unsignedInteger('min_points')->default(0)->comment('points in the window to reach it');
            $table->unsignedInteger('multiplier_bp')->default(10000)->comment('10000 = ×1 on every ride');
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->bigInteger('balance')->default(0);
            $table->unsignedBigInteger('lifetime')->default(0)->comment('all points ever earned');
            $table->foreignId('tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('loyalty_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('points')->comment('+ earned / − spent');
            $table->bigInteger('balance_after');
            $table->string('kind', 16)->comment('ride / service / badge / challenge / redeem / admin');
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('note')->nullable()->comment('{key, variables}');
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('loyalty_badges', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('icon', 40)->default('star')->comment('an icon key the apps know');
            $table->string('rule', 20)->comment('rides / category_rides / services / spend_minor');
            $table->string('category', 32)->nullable()->comment('rule category_rides: the category slug');
            $table->unsignedInteger('threshold');
            $table->unsignedInteger('reward_points')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('loyalty_user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('loyalty_badges')->cascadeOnDelete();
            $table->dateTime('earned_at');
            $table->unique(['user_id', 'badge_id']);
        });

        Schema::create('loyalty_challenges', function (Blueprint $table) {
            $table->id();
            $table->json('title');
            $table->json('description')->nullable();
            $table->string('rule', 20)->comment('rides / category_rides / services / spend_minor / clean_rides');
            $table->string('category', 32)->nullable();
            $table->unsignedInteger('target');
            $table->unsignedInteger('reward_points');
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('loyalty_challenge_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained('loyalty_challenges')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('progress')->default(0);
            $table->boolean('failed')->default(false)->comment('clean_rides: a cancellation in the period');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'user_id']);
        });

        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->json('title');
            $table->json('description')->nullable();
            $table->unsignedInteger('cost_points');
            $table->string('kind', 16)->comment('wallet_credit / coupon');
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('value_minor')->default(0)->comment('wallet credit, or a fixed coupon');
            $table->unsignedTinyInteger('coupon_percent')->nullable()->comment('a percent coupon instead');
            $table->unsignedBigInteger('coupon_max_minor')->nullable();
            $table->unsignedSmallInteger('coupon_days')->default(30);
            $table->foreignId('min_tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $table->unsignedInteger('stock')->nullable()->comment('null = unlimited');
            $table->unsignedSmallInteger('per_user_limit')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained('loyalty_rewards')->cascadeOnDelete();
            $table->unsignedInteger('points');
            $table->json('result')->nullable()->comment('{coupon_code} / {credit_minor}');
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['loyalty_redemptions', 'loyalty_rewards', 'loyalty_challenge_progress', 'loyalty_challenges', 'loyalty_user_badges', 'loyalty_badges', 'loyalty_entries', 'loyalty_accounts', 'loyalty_tiers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
