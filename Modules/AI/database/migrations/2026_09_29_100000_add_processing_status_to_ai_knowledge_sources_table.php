<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chunking + embedding a knowledge source (AiKnowledgeIngestionService::
     * indexChunks(), called once per admin-submitted document) used to run
     * synchronously inside the admin's HTTP request - one embedding API
     * call per chunk, so a long document could mean dozens of sequential
     * OpenAI calls blocking that single request and risking a timeout.
     * This column lets ingest()/reingest() return immediately (source
     * created, indexing queued via IndexAiKnowledgeSourceJob) while the
     * admin UI shows real progress instead of a blocked spinner.
     */
    public function up(): void
    {
        Schema::table('ai_knowledge_sources', function (Blueprint $table) {
            $table->string('processing_status', 20)->default('pending')->after('approval_status')
                ->comment('pending (لسه مستني الفهرسة) / processing (جاري التقطيع والـ embedding) / ready (جاهز للاسترجاع) / failed (فشلت الفهرسة - راجع processing_error)');

            $table->text('processing_error')->nullable()->after('processing_status')
                ->comment('رسالة الخطأ لو processing_status = failed، عشان الأدمن يعرف السبب من غير ما يفتح الـ logs');

            $table->timestamp('indexed_at')->nullable()->after('processing_error')
                ->comment('امتى انتهت آخر فهرسة ناجحة (أول ingest أو أي reingest بعد كده)');

            $table->index(['processing_status'], 'ai_knowledge_sources_processing_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_sources', function (Blueprint $table) {
            $table->dropIndex('ai_knowledge_sources_processing_status_index');
            $table->dropColumn(['processing_status', 'processing_error', 'indexed_at']);
        });
    }
};
