<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 (doc S8/S9): deliberately lean, following ai_knowledge_chunks'
 * own precedent rather than one typed column per format-specific source
 * reference (page/section/sheet_name/row_start/row_end/slide_number/
 * timestamp_start/timestamp_end/path all live in the single flexible
 * `metadata` JSON column instead - same pattern already used by
 * ai_files.metadata since Phase 1). `parent_chunk_id` is deliberately
 * omitted entirely: no Phase 8 strategy produces parent/child chunk
 * hierarchies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_file_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('file_id')
                ->constrained('ai_files')
                ->cascadeOnDelete()
                ->comment('الملف اللي القطعة دي مستخرجة منه');

            $table->unsignedInteger('content_version')->default(1)->comment('نسخة محتوى الملف وقت إنشاء القطعة دي - منسخة أقدم متفضلش نشطة لو الملف أعيد فهرسته');
            $table->unsignedInteger('chunk_index')->default(0)->comment('ترتيب القطعة دي داخل الملف/النسخة دي');

            // Deterministic identity (doc S31/S32): file_id + content_version +
            // chunk_index + checksum - stable across identical re-processing,
            // enforced as a real DB unique constraint so a retried job can
            // never insert a duplicate active row, not just "by convention".
            $table->string('chunk_key', 191)->comment('مفتاح ثابت (file_id+version+index+checksum) يمنع تكرار القطعة لو اتعاد تنفيذ الجوب');

            $table->string('content_ref')->comment('مكان تخزين نص القطعة الفعلي (مش المحتوى نفسه في الجدول) - زي نفس نمط ai_knowledge_chunks');
            $table->string('content_type', 40)->comment('document|html|data|spreadsheet|presentation|audio_transcript|video_transcript|image_reference|table');

            $table->unsignedInteger('token_count')->default(0);
            $table->boolean('token_count_is_estimated')->default(true)->comment('false بس لو فيه tokenizer حقيقي متاح - لسه مفيش فعليًا فى هذا المشروع');
            $table->unsignedInteger('character_count')->default(0);

            $table->string('checksum', 64)->comment('md5 للمحتوى المنظّف + الميتاداتا - لإعادة الفهرسة/كشف التغيير');
            $table->json('metadata')->nullable()->comment('page/section/sheet_name/row_start/row_end/slide_number/timestamp_start/timestamp_end/path - حسب نوع المحتوى');

            $table->string('status', 20)->default('pending')->comment('pending|indexing|indexed|failed|stale|deleted');
            $table->boolean('is_active')->default(true)->comment('false للقطع القديمة بعد إعادة فهرسة الملف - النسخ القديمة متفضلش نشطة أبدًا');
            $table->timestamp('indexed_at')->nullable();

            $table->timestamps();

            $table->unique('chunk_key', 'ai_file_chunks_chunk_key_unique');
            $table->index(['file_id', 'status']);
            $table->index(['file_id', 'is_active']);
            $table->index('content_type');
            $table->index('checksum');
            $table->index('content_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_file_chunks');
    }
};
