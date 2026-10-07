<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group decisions (spec 119–120): any message in a group becomes a decision to vote on (a poll
 * that answers it); the group's admins approve or reject it, and approved ones form the group's
 * decisions log with their outcome and date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_group_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('chat_messages')->nullOnDelete()->comment('the message it was made from');
            $table->foreignId('poll_message_id')->nullable()->constrained('chat_messages')->nullOnDelete()->comment('the vote');
            $table->string('title', 300);
            $table->string('status', 12)->default('open')->comment('open | approved | rejected');
            $table->string('outcome', 300)->nullable();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->foreignId('decided_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_decisions');
    }
};
