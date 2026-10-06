<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threads (spec 122): a side discussion under one message. Its replies carry `thread_id` (the
 * message they hang from) and stay out of the chat's main timeline; the message keeps how many
 * replies it has and when the last one came.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->after('reply_to_id')->constrained('chat_messages')->nullOnDelete()->comment('the message this one replies to in a thread');
            $table->unsignedInteger('thread_replies_count')->default(0)->after('thread_id');
            $table->timestamp('thread_last_at')->nullable()->after('thread_replies_count');
            $table->index(['conversation_id', 'thread_id'], 'chat_messages_thread_index');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('chat_messages_thread_index');
            $table->dropConstrainedForeignId('thread_id');
            $table->dropColumn(['thread_replies_count', 'thread_last_at']);
        });
    }
};
