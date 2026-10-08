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
        Schema::create('ai_language_evaluations', function (Blueprint $table) {
            $table->id();

            $table->string('name')->comment('اسم مجموعة الاختبار (مثال: Arabic Core Accuracy Suite)');

            $table->foreignId('language_id')
                ->constrained('ai_languages')
                ->cascadeOnDelete()
                ->comment('اللغة اللي مجموعة الاختبار دي بتقيّم جودة الرد بيها');

            $table->foreignId('variant_id')->nullable()
                ->constrained('ai_language_variants')
                ->nullOnDelete()
                ->comment('اللهجة/الأسلوب المحدد اللي بيتقيّم (اختياري - ممكن يكون تقييم عام للغة)');

            $table->unsignedInteger('test_case_count')->default(0)->comment('عدد حالات الاختبار في المجموعة دي');
            $table->decimal('pass_rate', 5, 2)->nullable()->comment('نسبة نجاح الاختبارات (%) - آخر نتيجة تقييم');

            $table->string('status')->default('pending')
                ->comment('حالة التقييم: pending (لسه ماتعملش) / running (جاري) / passed (نجح) / failed (فشل)');

            $table->timestamp('evaluated_at')->nullable()->comment('تاريخ آخر مرة اتعمل فيها التقييم فعلياً');
            $table->text('notes')->nullable()->comment('ملاحظات إضافية عن نتيجة التقييم');

            $table->timestamps();

            $table->index(['language_id', 'status'], 'ai_language_evaluations_language_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_language_evaluations');
    }
};
