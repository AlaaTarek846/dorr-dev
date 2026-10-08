<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR Sports (spec 183–200, docs/sports-plan.md) — the engine:
 *  - sports_settings — on/off, countries, live interval per tier, the budget reserve (182-style switches);
 *  - sports_sports — the provider's sports, each on/off with a guaranteed share of the one daily budget;
 *  - sports_competitions (+ translations) — every competition comes from the provider (184), the
 *    admin gives it a tier (big · normal · minor · off) and a scope (international … domestic);
 *  - sports_teams (+ translations) — clubs and national teams, with their colours;
 *  - sports_matches — one fixture in any sport: a unified status, scores per period, the minute;
 *  - sports_match_events / sports_match_details — goals and cards, statistics and line-ups;
 *  - sports_standings — tables and groups, with the provider's last update (192);
 *  - sports_api_usage — every provider request, per day, sport and endpoint (the budget governor).
 * Every timestamp is nullable: strict MySQL refuses a second NOT NULL timestamp without a default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->json('enabled_countries')->nullable()->comment('country ids; null = everywhere');
            $table->json('tier_seconds')->nullable()->comment('{big, normal, minor} live interval in seconds');
            $table->unsignedTinyInteger('reserve_percent')->default(10)->comment('kept for schedules and postponements');
            $table->unsignedInteger('daily_limit')->nullable()->comment('override; else the provider\'s own limit');
            $table->timestamps();
        });

        Schema::create('sports_sports', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->boolean('status')->default(false);
            $table->unsignedTinyInteger('min_share_percent')->default(5)->comment('guaranteed part of the daily budget');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sports_competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->constrained('sports_sports')->cascadeOnDelete();
            $table->unsignedBigInteger('provider_id');
            $table->string('name', 160);
            $table->string('type', 20)->nullable()->comment('league | cup');
            $table->string('scope', 20)->nullable()->comment('international | continental | regional | domestic');
            $table->string('country_name', 80)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('logo', 500)->nullable();
            $table->string('flag', 500)->nullable();
            $table->string('season', 20)->nullable();
            $table->date('season_start')->nullable();
            $table->date('season_end')->nullable();
            $table->json('coverage')->nullable();
            $table->string('tier', 10)->default('off')->comment('big | normal | minor | off');
            $table->unsignedInteger('priority')->default(0)->comment('higher first');
            $table->json('top_scorers')->nullable();
            $table->timestamp('standings_synced_at')->nullable();
            $table->timestamp('scorers_synced_at')->nullable();
            $table->boolean('standings_dirty')->default(false);
            $table->timestamps();
            $table->unique(['sport_id', 'provider_id']);
            $table->index(['tier', 'priority']);
        });
        Schema::create('sports_competition_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_competition_id')->constrained('sports_competitions')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 160);
            $table->unique(['sports_competition_id', 'locale'], 'sports_comp_tr_locale_unique');
        });

        Schema::create('sports_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->constrained('sports_sports')->cascadeOnDelete();
            $table->unsignedBigInteger('provider_id');
            $table->string('name', 160);
            $table->string('code', 10)->nullable();
            $table->string('logo', 500)->nullable();
            $table->string('country', 80)->nullable();
            $table->boolean('national')->default(false);
            $table->json('colors')->nullable()->comment('{primary, secondary} from line-ups');
            $table->timestamps();
            $table->unique(['sport_id', 'provider_id']);
        });
        Schema::create('sports_team_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_team_id')->constrained('sports_teams')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 160);
            $table->unique(['sports_team_id', 'locale']);
        });

        Schema::create('sports_matches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sport_id')->constrained('sports_sports')->cascadeOnDelete();
            $table->foreignId('competition_id')->constrained('sports_competitions')->cascadeOnDelete();
            $table->unsignedBigInteger('provider_id');
            $table->string('season', 20)->nullable();
            $table->string('round', 120)->nullable();
            $table->foreignId('home_team_id')->nullable()->constrained('sports_teams')->nullOnDelete();
            $table->foreignId('away_team_id')->nullable()->constrained('sports_teams')->nullOnDelete();
            $table->timestamp('starts_at')->nullable()->comment('UTC');
            $table->string('status', 20)->default('scheduled')->comment('scheduled | live | break | finished | postponed | cancelled | suspended');
            $table->string('status_code', 10)->nullable()->comment('the provider\'s own (1H, HT, Q3…)');
            $table->string('status_label', 60)->nullable();
            $table->unsignedSmallInteger('elapsed')->nullable()->comment('minute');
            $table->unsignedSmallInteger('elapsed_extra')->nullable();
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->json('scores')->nullable()->comment('per period, half time, extra time, penalties');
            $table->string('winner', 5)->nullable()->comment('home | away | draw');
            $table->string('venue', 160)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('referee', 120)->nullable();
            $table->unsignedInteger('version')->default(0)->comment('bumps on every change the apps see');
            $table->timestamp('live_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('details_synced_at')->nullable();
            $table->timestamp('lineups_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['sport_id', 'provider_id']);
            $table->index(['status', 'starts_at']);
            $table->index(['competition_id', 'starts_at']);
            $table->index(['home_team_id', 'starts_at']);
            $table->index(['away_team_id', 'starts_at']);
        });

        Schema::create('sports_match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('sports_matches')->cascadeOnDelete();
            $table->string('key', 40);
            $table->unsignedSmallInteger('minute')->nullable();
            $table->unsignedSmallInteger('extra')->nullable();
            $table->string('side', 5)->nullable()->comment('home | away');
            $table->string('type', 20)->comment('goal | own_goal | penalty | missed_penalty | yellow | second_yellow | red | sub | var | other');
            $table->string('player', 120)->nullable();
            $table->string('assist', 120)->nullable();
            $table->string('detail', 120)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['match_id', 'key']);
        });

        Schema::create('sports_match_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->unique()->constrained('sports_matches')->cascadeOnDelete();
            $table->json('statistics')->nullable()->comment('[{side, items[{type, home, away}]}]');
            $table->json('lineups')->nullable()->comment('{home{formation, coach, start[], subs[], colors}, away{…}}');
            $table->timestamps();
        });

        Schema::create('sports_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('sports_competitions')->cascadeOnDelete();
            $table->string('season', 20)->nullable();
            $table->string('group_name', 120)->default('');
            $table->unsignedSmallInteger('rank');
            $table->foreignId('team_id')->constrained('sports_teams')->cascadeOnDelete();
            $table->smallInteger('points')->default(0);
            $table->unsignedSmallInteger('played')->default(0);
            $table->unsignedSmallInteger('win')->default(0);
            $table->unsignedSmallInteger('draw')->default(0);
            $table->unsignedSmallInteger('lose')->default(0);
            $table->unsignedSmallInteger('goals_for')->default(0);
            $table->unsignedSmallInteger('goals_against')->default(0);
            $table->smallInteger('goal_diff')->default(0);
            $table->string('form', 20)->nullable();
            $table->string('description', 160)->nullable()->comment('promotion, relegation…');
            $table->string('trend', 5)->nullable()->comment('up | down | same');
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'season', 'group_name', 'team_id']);
        });

        Schema::create('sports_api_usage', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('sport_key', 30);
            $table->string('endpoint', 40);
            $table->unsignedInteger('requests')->default(0);
            $table->unique(['date', 'sport_key', 'endpoint']);
        });
    }

    public function down(): void
    {
        foreach (['sports_api_usage', 'sports_standings', 'sports_match_details', 'sports_match_events', 'sports_matches', 'sports_team_translations',
            'sports_teams', 'sports_competition_translations', 'sports_competitions', 'sports_sports', 'sports_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
