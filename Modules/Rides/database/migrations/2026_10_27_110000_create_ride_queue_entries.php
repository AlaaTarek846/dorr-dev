<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 4 of docs/rides_tasks.md — the airport queue (D-276..D-280): captains waiting their turn at an
 * airport zone. A row is a place in line; it stays after the captain leaves (why, and how long they
 * waited — that is what the wait estimate learns from). One place in line per captain at a time:
 * `active_driver_id` is the captain's id while waiting and null after, under a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('ride_zones')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('status', 8)->default('waiting')->comment('waiting / left');
            $table->string('entry', 8)->comment('auto / manual (queue.entry)');
            $table->unsignedInteger('position_at_join');
            $table->timestamp('joined_at');
            $table->timestamp('outside_since')->nullable()->comment('left the zone; out of the line after queue.leave_grace_s');
            $table->timestamp('left_at')->nullable();
            $table->string('left_reason', 16)->nullable()->comment('manual / offline / stale / left_zone / timeout / got_trip / removed');
            $table->unsignedInteger('wait_s')->nullable()->comment('joined → left');
            $table->unsignedBigInteger('active_driver_id')->nullable()->unique();
            $table->timestamps();
            $table->index(['zone_id', 'status', 'joined_at']);
            $table->index(['zone_id', 'left_reason', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_queue_entries');
    }
};
