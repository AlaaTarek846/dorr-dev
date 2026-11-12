<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Rating by criteria, as rides do it (rides O-41, owner 2026-11-12): next to the overall stars, the customer scores the
 * establishment on each criterion of `moving.rating_criteria` (on time, care with the furniture, the team…); the
 * averages show with the establishment's offers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moving_rating_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_id')->constrained('moving_ratings')->cascadeOnDelete();
            $table->string('criterion', 32);
            $table->unsignedTinyInteger('stars')->comment('1..5');
            $table->unique(['rating_id', 'criterion']);
            $table->index('criterion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moving_rating_scores');
    }
};
