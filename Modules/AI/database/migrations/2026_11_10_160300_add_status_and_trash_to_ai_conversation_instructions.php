<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI conversation instructions get the countries list (filters, toggle, counts, trash) - without a translation table:
 * the instruction is a prompt sent to the AI as it is (technical, never translated).
 * - is_active is renamed status (the conversation_id + is_active index follows the column);
 * - soft deletes. A trashed instruction is never sent to the AI (AiChatService), like a switched-off one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversation_instructions', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_conversation_instructions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversation_instructions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('ai_conversation_instructions', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });
    }
};
