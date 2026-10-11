<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR Sports — following and alerts (spec 189, 193, 194, docs/sports-plan.md §4):
 *  - sports_follows — any number of teams / national teams / competitions in any sport, each
 *    with its own alert choices and "no spoilers";
 *  - sports_preferences — no spoilers everywhere, celebration level (off · calm · normal ·
 *    festive), sound, vibration, the reminder before a match, goals during quiet hours;
 *  - sports_notification_log — one alert of a kind per person per match, never twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_follows', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('sport_id')->constrained('sports_sports')->cascadeOnDelete();
            $table->string('kind', 15)->comment('team | competition');
            $table->unsignedBigInteger('target_id');
            $table->json('alerts')->nullable()->comment('{reminder, kickoff, goal, red_card, half_time, finished, schedule, lineups}');
            $table->boolean('no_spoilers')->default(false);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'kind', 'target_id']);
            $table->index(['kind', 'target_id']);
            $table->index(['sport_id', 'kind']);
        });

        Schema::create('sports_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->boolean('no_spoilers')->default(false);
            $table->string('celebration', 10)->default('normal')->comment('off | calm | normal | festive');
            $table->boolean('sounds')->default(true);
            $table->boolean('vibrate')->default(true);
            $table->unsignedSmallInteger('reminder_minutes')->default(15);
            $table->boolean('goals_in_quiet')->default(false);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create('sports_notification_log', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('match_id')->constrained('sports_matches')->cascadeOnDelete();
            $table->string('key', 80);
            $table->timestamp('sent_at')->nullable();
            $table->unique(['owner_type', 'owner_id', 'match_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_notification_log');
        Schema::dropIfExists('sports_preferences');
        Schema::dropIfExists('sports_follows');
    }
};
