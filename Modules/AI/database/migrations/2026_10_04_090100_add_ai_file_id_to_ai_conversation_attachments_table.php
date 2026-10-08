<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Universal AI File Engine - Phase 1: links the chat attachment row (the
 * one the Android/Vue UI actually reads for instant preview - untouched,
 * see AiConversationAttachment) to its AiFileEngine-processed counterpart
 * in `ai_files` (real extraction, chunking/indexing eligibility) without
 * replacing or duplicating any existing column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversation_attachments', function (Blueprint $table) {
            $table->foreignId('ai_file_id')->nullable()->after('message_id')
                ->constrained('ai_files')
                ->nullOnDelete()
                ->comment('الصف المقابل فى نظام الملفات الموحّد (AiFileEngine) - فاضي لحد ما المعالجة تخلص');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversation_attachments', function (Blueprint $table) {
            $table->dropForeign(['ai_file_id']);
            $table->dropColumn('ai_file_id');
        });
    }
};
