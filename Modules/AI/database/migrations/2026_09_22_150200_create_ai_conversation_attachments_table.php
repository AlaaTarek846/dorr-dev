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
        Schema::create('ai_conversation_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('ai_conversations')
                ->cascadeOnDelete()
                ->comment('المحادثة اللي المرفق ده جزء منها');

            $table->foreignId('message_id')->nullable()
                ->constrained('ai_messages')
                ->nullOnDelete()
                ->comment('الرسالة المحددة اللي المرفق ده مرتبط بيها (اختياري - ممكن يكون مرفق عام على المحادثة كلها)');

            // ملحوظة: المشروع ده لسه مفيهوش نظام ملفات مركزي (جدول files) تقدر تتربط بيه،
            // فبنخزن بيانات الملف مباشرة هنا بدل foreign key، لحد ما يتضاف نظام ملفات موحد لاحقاً
            $table->string('file_name')->comment('اسم الملف الأصلي زي ما اليوزر رفعه');
            $table->string('file_path')->nullable()->comment('مسار تخزين الملف الفعلي على السيرفر/الـ storage');
            $table->string('mime_type')->nullable()->comment('نوع الملف (application/pdf, image/png...الخ)');
            $table->unsignedBigInteger('file_size')->nullable()->comment('حجم الملف بالبايت');

            $table->timestamps();

            $table->index(['conversation_id', 'message_id'], 'ai_conversation_attachments_conversation_message_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_attachments');
    }
};
