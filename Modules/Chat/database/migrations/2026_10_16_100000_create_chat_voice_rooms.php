<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing voice rooms (spec 91): one per group or channel, always there — members drop in and
 * out like a voice channel, nobody rings anybody. On LiveKit, like calls. Who may speak:
 * everyone, or only those the hosts let speak (the others listen and raise a hand). Members who
 * vanish without leaving are swept out after a minute and a half without a heartbeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_voice_rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->unique()->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('room_name', 80)->unique();
            $table->string('title', 120)->nullable();
            $table->string('speak_policy', 12)->default('everyone')->comment('everyone · approved (hosts let people speak)');
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_voice_room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('chat_voice_rooms')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('role', 10)->default('speaker')->comment('host · speaker · listener');
            $table->timestamp('hand_raised_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->unique(['room_id', 'participant_id']);
            $table->index(['room_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_voice_room_members');
        Schema::dropIfExists('chat_voice_rooms');
    }
};
