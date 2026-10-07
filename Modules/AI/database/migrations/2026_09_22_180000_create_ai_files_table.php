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
        Schema::create('ai_files', function (Blueprint $table) {
            $table->id();

            // صاحب الملف: ممكن يكون يوزر أو مقدم خدمة (alias قصير - شوف AIServiceProvider::boot)
            $table->string('owner_type')->comment('نوع صاحب الملف (user أو provider)');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب الملف في جدول الـ users أو الـ providers حسب owner_type');

            $table->string('source_type')->default('upload')
                ->comment('من فين جه الملف: upload (رفع مباشر) / project (من مشروع) / conversation (من محادثة) / external (مصدر خارجي)');

            // ملحوظة: المشروع ده لسه مفيهوش نظام ملفات مركزي (جدول files) تقدر تتربط بيه،
            // فبنخزن بيانات الملف مباشرة هنا بدل foreign key، لحد ما يتضاف نظام ملفات موحد لاحقاً
            $table->string('file_name')->comment('اسم الملف الأصلي زي ما اليوزر رفعه');
            $table->string('file_path')->nullable()->comment('مسار تخزين الملف الفعلي على السيرفر/الـ storage');
            $table->string('mime_type')->nullable()->comment('نوع الملف (application/pdf, image/png...الخ)');
            $table->unsignedBigInteger('file_size')->nullable()->comment('حجم الملف بالبايت');

            $table->string('processing_status')->default('pending')
                ->comment('حالة تجهيز الملف للاستخدام مع الـ AI: pending (لسه) / processing (جاري المعالجة) / ready (جاهز) / rejected (مرفوض)');

            $table->timestamps();

            $table->index(['owner_type', 'owner_id'], 'ai_files_owner_type_owner_id_index');
            $table->index('processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_files');
    }
};
