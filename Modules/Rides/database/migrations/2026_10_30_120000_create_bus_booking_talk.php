<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * O-68: the bus captain and each passenger talk inside the app — a chat per ticket and in-app voice calls
 * (LiveKit, like a ride's: docs/rides_tasks.md O-09). Numbers are never exchanged. The chat stays after the
 * trip, so the admin can read it on a complaint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_booking_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_booking_id')->constrained('bus_bookings')->cascadeOnDelete();
            $table->string('sender_type', 8)->comment('user / driver');
            $table->unsignedBigInteger('sender_id');
            $table->string('kind', 8)->default('text')->comment('text / quick');
            $table->string('quick_key', 32)->nullable();
            $table->text('body');
            $table->string('client_id', 64)->nullable()->comment('the same message sent twice (weak network)');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['bus_booking_id', 'id']);
            $table->unique(['bus_booking_id', 'sender_type', 'client_id']);
        });

        Schema::create('bus_booking_calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bus_booking_id')->constrained('bus_bookings')->cascadeOnDelete();
            $table->string('caller', 8)->comment('user / driver');
            $table->string('room', 80);
            $table->string('status', 10)->default('ringing')->comment('ringing / active / ended / declined / missed / canceled');
            $table->dateTime('answered_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('ended_by', 8)->nullable();
            $table->timestamps();
            $table->index(['bus_booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_booking_calls');
        Schema::dropIfExists('bus_booking_messages');
    }
};
