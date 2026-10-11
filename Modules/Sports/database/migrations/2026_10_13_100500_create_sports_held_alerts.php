<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts held by quiet hours (spec 193): one row per person and match; when the quiet hours end,
 * `sports:reminders` sends one summary with each match's score as it is then (or no score for
 * "no spoilers").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_held_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('match_id')->constrained('sports_matches')->cascadeOnDelete();
            $table->boolean('hidden')->default(false);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'match_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_held_alerts');
    }
};
