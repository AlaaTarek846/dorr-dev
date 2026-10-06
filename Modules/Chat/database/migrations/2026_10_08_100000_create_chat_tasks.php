<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * My tasks (spec 38): to-dos I saved — by hand or from a message ("make it tasks", DORR AI) — with
 * an optional due time that notifies me once. Only mine; nobody in the chat knows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('text', 300);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('chat_conversations')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'done_at']);
            $table->index(['due_at', 'reminded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_tasks');
    }
};
