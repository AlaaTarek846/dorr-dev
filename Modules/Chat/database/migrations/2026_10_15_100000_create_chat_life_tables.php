<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily intelligence in the chat, batch A (spec 148, 149, 152, 154 — docs/remaining_chat.md):
 *  - chat_commitments — who will do what, by when; the one responsible accepts it;
 *  - chat_handoffs — a message, file or request handed to someone allowed, who takes it on;
 *  - chat_agreements — the terms the parties agree on (versioned, every party approves each
 *    version; a record only — nothing operational happens from it);
 *  - chat_life_log — the audit trail of all three.
 * Each one has its card in the chat (message types commitment · handoff · agreement) and keeps
 * a link to the message it came from. Every timestamp is nullable (strict MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_commitments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('card_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('created_by_participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->foreignId('assignee_participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('title', 300);
            $table->timestamp('due_at')->nullable();
            $table->unsignedSmallInteger('remind_minutes')->nullable()->comment('before due_at; null = no reminder');
            $table->string('status', 12)->default('proposed')->comment('proposed · accepted · declined · done · cancelled');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['assignee_participant_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('chat_handoffs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete()->comment('where the card is');
            $table->foreignId('source_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('card_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('from_participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->foreignId('to_participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('note', 500)->nullable();
            $table->string('status', 12)->default('pending')->comment('pending · accepted · declined · done · cancelled');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['to_participant_id', 'status']);
        });

        Schema::create('chat_agreements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('card_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('created_by_participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('title', 200);
            $table->json('terms')->comment('[{label, value}] — date, scope, proposed price, notes…');
            $table->json('parties')->comment('participant ids who must approve');
            $table->json('approvals')->nullable()->comment('{participant id: {decision: accepted|declined, at}} for this version');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 12)->default('proposed')->comment('proposed · agreed · declined · cancelled');
            $table->timestamp('agreed_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'status']);
        });

        Schema::create('chat_life_log', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 12)->comment('commitment · handoff · agreement');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('actor_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->string('action', 20);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['chat_life_log', 'chat_agreements', 'chat_handoffs', 'chat_commitments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
