<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Block 06 — AI and DORR AI in rides:
 *  - «راحتي»: the rider's saved trip preferences, and the ones a ride carries (with what the captain couldn't do);
 *  - the next trip: a captain may switch it off;
 *  - the risks the system works out (lateness of a booking, cancelling, fraud signs) and DORR AI's reading of a case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_ride_profiles', function (Blueprint $table) {
            $table->json('comfort')->nullable()->comment('«راحتي»: {quiet, ac, music, notes[]} — preferences, never guarantees');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->json('comfort')->nullable()->comment('The rider\'s «راحتي» for this ride + {unable[{item, reason, at}]} from the captain');
            $table->json('late_risk')->nullable()->comment('A booking\'s captain may be late: {late_s, reason, eta_s, alerted_at}');
            $table->unsignedTinyInteger('cancel_risk')->nullable()->comment('0–100: how likely this ride is to be cancelled (AI-10)');
            $table->unsignedTinyInteger('risk_score')->nullable()->comment('0–100: fraud signs found at the end (AI-06)');
            $table->json('risk_flags')->nullable()->comment('[{code, detail}] — fraud signs, for the team to look at');
            $table->foreignId('after_ride_id')->nullable()->comment('The next trip: taken while the captain was finishing this ride');
            $table->index('risk_score');
        });

        Schema::table('driver_preferences', function (Blueprint $table) {
            $table->boolean('next_trips')->default(true)->comment('Offer me a next trip near where my current ride ends');
        });

        Schema::table('ride_cases', function (Blueprint $table) {
            $table->json('ai_triage')->nullable()->comment('DORR AI\'s reading of the case for the team: {category, priority, summary, route, points[], at}');
        });
    }

    public function down(): void
    {
        Schema::table('ride_cases', fn (Blueprint $table) => $table->dropColumn('ai_triage'));
        Schema::table('driver_preferences', fn (Blueprint $table) => $table->dropColumn('next_trips'));
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex(['risk_score']);
            $table->dropColumn(['comfort', 'late_risk', 'cancel_risk', 'risk_score', 'risk_flags', 'after_ride_id']);
        });
        Schema::table('rider_ride_profiles', fn (Blueprint $table) => $table->dropColumn('comfort'));
    }
};
