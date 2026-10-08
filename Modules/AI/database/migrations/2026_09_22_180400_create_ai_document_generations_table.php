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
        Schema::create('ai_document_generations', function (Blueprint $table) {
            $table->id();

            // صاحب طلب التوليد: ممكن يكون يوزر أو مقدم خدمة (alias قصير - شوف AIServiceProvider::boot)
            $table->string('owner_type')->comment('نوع صاحب طلب التوليد (user أو provider)');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب طلب التوليد في جدول الـ users أو الـ providers حسب owner_type');

            $table->foreignId('conversation_id')->nullable()
                ->constrained('ai_conversations')
                ->nullOnDelete()
                ->comment('المحادثة اللي طلب توليد المستند ده جه منها (اختياري)');

            $table->text('prompt')->nullable()->comment('نص طلب اليوزر اللي أدى لتوليد المستند ده (مثال: "اعمللي تقرير Word")');

            $table->string('output_format')->default('pdf')
                ->comment('صيغة الملف الناتج: pdf / docx / txt / html / csv');

            // ملحوظة: المشروع ده لسه مفيهوش نظام ملفات مركزي (جدول files) تقدر تتربط بيه،
            // فبنخزن بيانات الملف الناتج مباشرة هنا بدل foreign key لحد ما يتضاف نظام ملفات موحد لاحقاً
            $table->string('output_file_name')->nullable()->comment('اسم الملف الناتج بعد ما يخلص التوليد');
            $table->string('output_file_path')->nullable()->comment('مسار تخزين الملف الناتج على السيرفر/الـ storage');

            $table->string('status')->default('pending')
                ->comment('حالة التوليد: pending (لسه) / generating (جاري التوليد) / preview_ready (جاهز للمعاينة) / completed (اكتمل) / failed (فشل)');

            $table->timestamps();

            $table->index(['owner_type', 'owner_id'], 'ai_document_generations_owner_type_owner_id_index');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_document_generations');
    }
};
