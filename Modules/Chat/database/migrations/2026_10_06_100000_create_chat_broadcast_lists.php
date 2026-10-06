<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Broadcast lists (like WhatsApp's): one message to many people, each getting it in their own
 * one-to-one chat with me — only those who have me saved receive it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_broadcast_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 100)->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('chat_broadcast_list_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('chat_broadcast_lists')->cascadeOnDelete();
            $table->string('participant_type', 32);
            $table->unsignedBigInteger('participant_id');
            $table->timestamps();
            $table->unique(['list_id', 'participant_type', 'participant_id'], 'chat_broadcast_members_unique');
        });

        // What I sent to a list (its own "chat" of my broadcasts).
        Schema::create('chat_broadcast_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('chat_broadcast_lists')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('body')->nullable();
            $table->json('message_ids')->comment('the messages it became, one per recipient (uuids)');
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_broadcast_messages');
        Schema::dropIfExists('chat_broadcast_list_members');
        Schema::dropIfExists('chat_broadcast_lists');
    }
};
