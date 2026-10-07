<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages written now and sent later (Telegram style). `chat:send-scheduled` sends the due ones
     * every minute as their author; the row keeps its uuid, which becomes the message's uuid, so a
     * crash halfway never sends one twice.
     */
    public function up(): void
    {
        Schema::create('chat_scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->text('body');
            $table->boolean('is_silent')->default(false);
            $table->timestamp('send_at')->nullable();
            $table->string('status', 16)->default('pending')->comment('pending | sent | failed');
            $table->string('error_code', 64)->nullable()->comment('why it could not be sent (chat_* code)');
            $table->unsignedBigInteger('message_id')->nullable()->comment('the message it became');
            $table->timestamps();

            $table->index(['status', 'send_at']);
            $table->index(['owner_type', 'owner_id', 'conversation_id'], 'chat_scheduled_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_scheduled_messages');
    }
};
