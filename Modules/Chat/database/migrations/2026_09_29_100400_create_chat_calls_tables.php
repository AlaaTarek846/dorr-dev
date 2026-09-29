<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voice / video calls (docs/chat-plan.md §10.2). The media runs through LiveKit; these rows are
 * the call log and the ringing state machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('type', 8)->comment('audio | video');
            $table->string('status', 16)->comment('ringing | ongoing | ended | missed | declined | cancelled');
            $table->foreignId('initiator_participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('room_name', 80)->unique();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });

        Schema::create('chat_call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('chat_calls')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('status', 16)->comment('ringing | joined | declined | missed | left');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['call_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_call_participants');
        Schema::dropIfExists('chat_calls');
    }
};
