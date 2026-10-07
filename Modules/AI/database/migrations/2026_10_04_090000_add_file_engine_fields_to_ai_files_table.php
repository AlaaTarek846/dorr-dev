<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Universal AI File Engine - Phase 1 (additive only, no column dropped or
 * retyped): closes the real gaps in the already-existing `ai_files` table
 * (built earlier as dormant scaffolding for the admin knowledge base, never
 * wired to the chat upload flow) so it can serve as the single source of
 * truth for both chat attachments and knowledge-base sources, instead of
 * building a parallel table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_files', function (Blueprint $table) {
            $table->string('checksum')->nullable()->after('file_size')
                ->comment('SHA-256 لمحتوى الملف - لمنع إعادة معالجة نفس الملف لو اتبعت تاني (dedup)');

            $table->foreignId('conversation_id')->nullable()->after('source_type')
                ->constrained('ai_conversations')
                ->cascadeOnDelete()
                ->comment('المحادثة اللي الملف ده اتبعت فيها (لو source_type=conversation)');

            $table->foreignId('message_id')->nullable()->after('conversation_id')
                ->constrained('ai_messages')
                ->nullOnDelete()
                ->comment('الرسالة المحددة اللي الملف ده مرفق بيها');

            $table->text('processing_error')->nullable()->after('processing_status')
                ->comment('نص الخطأ لو processing_status=rejected/فشلت المعالجة');

            $table->json('metadata')->nullable()->after('processing_error')
                ->comment('بيانات وصفية مرنة حسب نوع الملف: page_count, sheet_count, slide_count, duration_seconds, width, height...الخ');

            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::table('ai_files', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropForeign(['message_id']);
            $table->dropIndex(['checksum']);
            $table->dropColumn(['checksum', 'conversation_id', 'message_id', 'processing_error', 'metadata']);
        });
    }
};
