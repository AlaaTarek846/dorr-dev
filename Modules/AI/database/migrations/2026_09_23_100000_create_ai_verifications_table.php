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
        Schema::create('ai_verifications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('request_id')
                ->comment('طلب الـ AI اللي التحقق ده بيتم على إجابته');

            $table->foreign('request_id')
                ->references('id')->on('ai_requests')
                ->cascadeOnDelete();

            $table->unsignedInteger('attempt_number')->default(1)
                ->comment('رقم محاولة التوليد اللي اتعمل عليها التحقق ده (1 = المسودة الأولى، وهكذا لو اتصححت)');

            $table->foreignId('verifier_provider_id')->nullable()
                ->constrained('ai_providers')
                ->nullOnDelete()
                ->comment('المزود/الموديل اللي عمل مراجعة التحقق - غالبا مختلف عن اللي ولّد الإجابة');

            $table->longText('draft_content')->nullable()
                ->comment('نص الإجابة قبل التحقق (المسودة اللي اتراجعت)');

            $table->json('claims')->nullable()
                ->comment('قائمة الادعاءات اللي المدقق استخرجها من المسودة، كل واحد بحالة الدعم بتاعته ودرجة الثقة والسبب');

            $table->json('issues')->nullable()
                ->comment('المشاكل اللي المدقق لقاها (تناقضات، معلومات ناقصة، ادعاءات مش مدعومة) - دي اللي بترجع للنموذج عشان يصحح');

            $table->decimal('supported_claims_ratio', 4, 3)->nullable()
                ->comment('نسبة الادعاءات المدعومة من إجمالي الادعاءات اللي اتستخرجت (0-1)');

            $table->decimal('completeness_score', 4, 3)->nullable()
                ->comment('تقييم المدقق لمدى اكتمال الإجابة بالنسبة للسؤال (0-1)');

            $table->decimal('evidence_strength', 4, 3)->nullable()
                ->comment('متوسط درجة ثقة المدقق في الادعاءات نفسها (0-1)');

            $table->decimal('confidence_score', 4, 3)->nullable()
                ->comment('درجة الثقة النهائية المحسوبة من المكونات أعلاه - مش رقم ثابت ولا تقييم النموذج لنفسه');

            $table->string('status', 30)->default('pending')
                ->comment('نتيجة التحقق: passed (اتقبلت) / needs_correction (هترجع تتصحح) / failed (فشلت بعد كل المحاولات) / abstained (النظام قرر يعتذر بدل ما يجاوب) / skipped (التحقق متعطل أو مفيش مدقق متاح)');

            $table->timestamps();

            $table->index(['request_id', 'attempt_number'], 'ai_verifications_request_attempt_index');
            $table->index(['status', 'created_at'], 'ai_verifications_status_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_verifications');
    }
};
