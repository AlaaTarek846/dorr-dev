<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * In-app voice calls between the rider and the captain of a ride (docs/rides_tasks.md O-09, X-13 decided:
 * LiveKit, the same as the chat's calls). One row per call: who called, its LiveKit room and what became
 * of it. Numbers are never exchanged — the call goes through DORR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->string('caller', 8)->comment('user / driver');
            $table->string('room', 80);
            $table->string('status', 10)->default('ringing')->comment('ringing / active / ended / declined / missed / canceled');
            $table->dateTime('answered_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('ended_by', 8)->nullable();
            $table->timestamps();
            $table->index(['ride_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_calls');
    }
};
