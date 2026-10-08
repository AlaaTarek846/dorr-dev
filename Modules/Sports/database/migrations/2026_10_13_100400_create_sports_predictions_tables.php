<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * DORR Sports — predictions, contests and prizes (spec 195, 196, 199; decision 2026-10-06,
 * docs/sports-plan.md §5):
 *  - sports_predictions — free, one per person per match, locked at kick-off, scored from the
 *    official result (exact score 3 points, the right winner 1);
 *  - sports_contests (+ translations) — the admin's contests on a match, a round or a whole
 *    competition, in chosen countries, with a prize (wallet credit that can't be withdrawn, a
 *    coupon, or a badge), how it's shared, caps and the review rule;
 *  - sports_contest_winners — who won what, and whether it's paid, waiting for review or refused;
 *  - sports_ratings — the community's fun / excitement rating after the match (199);
 *  - sports_match_rooms — chat groups to watch a match together (195);
 *  - sports_settings.predictions_enabled / prizes_countries — prizes are off until the admin opens
 *    them per country (legal review first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('sports_matches')->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('winner', 5)->comment('home | draw | away');
            $table->unsignedTinyInteger('home_score')->nullable();
            $table->unsignedTinyInteger('away_score')->nullable();
            $table->unsignedTinyInteger('points')->nullable();
            $table->boolean('exact')->nullable();
            $table->boolean('correct_winner')->nullable();
            $table->string('result', 10)->nullable()->comment('won | lost | void');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['match_id', 'owner_type', 'owner_id']);
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('sports_contests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('scope', 15)->comment('match | round | competition');
            $table->foreignId('match_id')->nullable()->constrained('sports_matches')->nullOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained('sports_competitions')->nullOnDelete();
            $table->string('round', 120)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable()->comment('round / competition: settled after this');
            $table->json('countries')->nullable()->comment('country ids it runs in');
            $table->string('rule', 10)->default('exact')->comment('exact | winner | points');
            $table->string('status', 12)->default('draft')->comment('draft | open | settled | cancelled');
            $table->string('prize_type', 10)->default('wallet')->comment('wallet | coupon | badge');
            $table->json('prize_amounts')->nullable()->comment('wallet: {country_id: amount_minor} per winner (or the pool when split)');
            $table->json('coupon')->nullable()->comment('{kind, value, max_discount_minor, purposes, valid_days}');
            $table->string('distribution', 10)->default('each')->comment('each | split | first_n');
            $table->unsignedInteger('max_winners')->nullable();
            $table->unsignedBigInteger('budget_minor')->nullable()->comment('a cap per country, in its currency');
            $table->unsignedSmallInteger('min_account_days')->default(7);
            $table->boolean('auto_pay')->default(true);
            $table->unsignedBigInteger('review_above_minor')->nullable()->comment('a prize above this waits for the admin');
            $table->string('seed', 40)->nullable()->comment('the recorded draw seed');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'scope']);
        });
        Schema::create('sports_contest_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_contest_id')->constrained('sports_contests')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 160);
            $table->text('terms')->nullable();
            $table->unique(['sports_contest_id', 'locale']);
        });

        Schema::create('sports_contest_winners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained('sports_contests')->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->unsignedSmallInteger('points')->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->string('prize_type', 10);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained('wallet_coupons')->nullOnDelete();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->string('status', 10)->default('pending')->comment('pending | review | paid | rejected');
            $table->string('note', 300)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['contest_id', 'owner_type', 'owner_id']);
        });

        Schema::create('sports_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('sports_matches')->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedTinyInteger('fun');
            $table->unsignedTinyInteger('excitement');
            $table->timestamps();
            $table->unique(['match_id', 'owner_type', 'owner_id']);
        });

        Schema::create('sports_match_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('sports_matches')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->timestamps();
        });

        Schema::table('sports_settings', function (Blueprint $table) {
            $table->boolean('predictions_enabled')->default(true)->after('daily_limit');
            $table->json('prizes_countries')->nullable()->after('predictions_enabled')->comment('country ids where prizes may be given; null = nowhere');
        });
        Cache::forget('sports.settings');
    }

    public function down(): void
    {
        Schema::table('sports_settings', fn (Blueprint $table) => $table->dropColumn(['predictions_enabled', 'prizes_countries']));
        foreach (['sports_match_rooms', 'sports_ratings', 'sports_contest_winners', 'sports_contest_translations', 'sports_contests', 'sports_predictions'] as $table) {
            Schema::dropIfExists($table);
        }
        Cache::forget('sports.settings');
    }
};
