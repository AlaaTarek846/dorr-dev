<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Rating by criteria (docs/rides_tasks.md O-41): next to the overall stars, the rider scores the captain
 * on each criterion (speed, cleanliness, driving…) and the captain scores the rider on theirs. The lists
 * are policies (`rating.driver_criteria`, `rating.rider_criteria`), so the admin changes them per place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_rating_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_id')->constrained('ratings')->cascadeOnDelete();
            $table->string('criterion', 32);
            $table->unsignedTinyInteger('stars')->comment('1..5');
            $table->unique(['rating_id', 'criterion']);
            $table->index('criterion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_rating_scores');
    }
};
