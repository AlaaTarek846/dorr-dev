<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_file_processing', function (Blueprint $table) {
            $table->id();

            $table->foreignId('file_id')
                ->constrained('ai_files')
                ->cascadeOnDelete()
                ->comment('الملف اللي خطوة المعالجة دي بتاعته');

            $table->string('processing_type')
                ->comment('نوع المعالجة: text_extraction (استخراج نص) / metadata_extraction (استخراج بيانات وصفية) / ocr (تعرف ضوئي للصور/المستندات المصورة) / parsing (تحليل بنية الملف) / index_preparation (تجهيز للفهرسة)');

            $table->string('status')->default('pending')
                ->comment('حالة خطوة المعالجة: pending / processing / completed / failed');

            $table->string('extracted_content_ref')->nullable()
                ->comment('مكان تخزين النص المستخرج (مرجع فقط - مش النص نفسه في الجدول)');

            $table->text('error_message')->nullable()->comment('نص الخطأ لو خطوة المعالجة فشلت');

            $table->timestamps();

            $table->index(['file_id', 'processing_type'], 'ai_file_processing_file_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_file_processing');
    }
};
