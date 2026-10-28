<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-106: how a freelancer can be reached — whether they take new requests (and until when), a guide price, the weekly hours
 * they work, and the time off they marked. One availability row per freelancer; time off is a list of dated periods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelancer_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->unique()->constrained('freelancers')->cascadeOnDelete();
            $table->boolean('accepting_requests')->default(true)->comment('false = paused: no new offers');
            $table->date('paused_until')->nullable()->comment('the pause ends by itself after this day');
            $table->string('pause_reason', 200)->nullable()->comment('private note, never shown to clients');
            $table->string('rate_type', 10)->default('none')->comment('hourly | fixed | none');
            $table->unsignedBigInteger('rate_min_minor')->nullable();
            $table->unsignedBigInteger('rate_max_minor')->nullable();
            $table->json('weekly_hours')->nullable()->comment('[{day: 0-6 (0 = Sunday), from: HH:MM, to: HH:MM}]');
            $table->timestamps();
        });

        Schema::create('freelancer_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 200)->nullable();
            $table->timestamps();
            $table->index(['freelancer_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelancer_time_off');
        Schema::dropIfExists('freelancer_availability');
    }
};
