<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR Sports, phase 6 (docs/sports-plan.md §10) — everything else the provider has, kept here:
 *  - sports_cache — one provider answer per (sport, endpoint, parameters), with how long it's good
 *    for and whether the plan refused it (squads, coaches, team and player statistics, transfers,
 *    trophies, injuries, predictions, head to head, leaders, rounds, odds…);
 *  - matches: the venue id (its photo), events with the player ids, the players' match ratings;
 *  - standings at home and away; a team's venue, founding year, coach and last captain;
 *  - the season schedule's last sync per competition; where odds may be shown (info only).
 * Every timestamp is nullable (strict MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_cache', function (Blueprint $table) {
            $table->id();
            $table->string('sport_key', 30);
            $table->string('endpoint', 60);
            $table->string('params_hash', 40);
            $table->json('params')->nullable();
            $table->longText('payload')->nullable()->comment('the provider\'s `response`, as JSON');
            $table->unsignedInteger('results')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refused_until')->nullable()->comment('the plan doesn\'t cover it: don\'t ask before');
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->unique(['sport_key', 'endpoint', 'params_hash'], 'sports_cache_key_unique');
            $table->index('expires_at');
        });

        Schema::table('sports_matches', function (Blueprint $table) {
            $table->unsignedBigInteger('venue_id')->nullable()->after('venue')->comment('the provider\'s venue id (its photo)');
        });
        Schema::table('sports_match_events', function (Blueprint $table) {
            $table->unsignedBigInteger('player_id')->nullable()->after('player');
            $table->unsignedBigInteger('assist_id')->nullable()->after('assist');
        });
        Schema::table('sports_match_details', function (Blueprint $table) {
            $table->json('players')->nullable()->after('lineups')->comment('{home[], away[]} minutes, rating, captain, goals…');
        });
        Schema::table('sports_standings', function (Blueprint $table) {
            $table->json('home')->nullable()->after('goal_diff')->comment('{played, win, draw, lose, goals_for, goals_against}');
            $table->json('away')->nullable()->after('home');
        });
        Schema::table('sports_teams', function (Blueprint $table) {
            $table->unsignedSmallInteger('founded')->nullable()->after('country');
            $table->json('venue')->nullable()->after('founded')->comment('{id, name, city, capacity, surface, image}');
            $table->json('coach')->nullable()->after('venue')->comment('{id, name, photo, nationality, age}');
            $table->json('captain')->nullable()->after('coach')->comment('the last match\'s captain {id, name, photo, number}');
            $table->timestamp('profile_synced_at')->nullable();
            $table->timestamp('season_synced_at')->nullable();
        });
        Schema::table('sports_competitions', function (Blueprint $table) {
            $table->timestamp('season_synced_at')->nullable()->after('scorers_synced_at');
        });
        Schema::table('sports_settings', function (Blueprint $table) {
            $table->json('odds_countries')->nullable()->comment('country ids where odds show (info only); null = nowhere');
        });
    }

    public function down(): void
    {
        Schema::table('sports_settings', fn (Blueprint $t) => $t->dropColumn('odds_countries'));
        Schema::table('sports_competitions', fn (Blueprint $t) => $t->dropColumn('season_synced_at'));
        Schema::table('sports_teams', fn (Blueprint $t) => $t->dropColumn(['founded', 'venue', 'coach', 'captain', 'profile_synced_at', 'season_synced_at']));
        Schema::table('sports_standings', fn (Blueprint $t) => $t->dropColumn(['home', 'away']));
        Schema::table('sports_match_details', fn (Blueprint $t) => $t->dropColumn('players'));
        Schema::table('sports_match_events', fn (Blueprint $t) => $t->dropColumn(['player_id', 'assist_id']));
        Schema::table('sports_matches', fn (Blueprint $t) => $t->dropColumn('venue_id'));
        Schema::dropIfExists('sports_cache');
    }
};
