<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.0 requirements doc §19.1/§19.2: an independent, dated, admin-
     * managed bank of benchmark prompts - split by domain, language,
     * country, task type and risk - including easy, hard, adversarial
     * and insufficient-information cases so the system's ability to
     * correctly abstain can actually be measured, not just its ability
     * to answer.
     */
    public function up(): void
    {
        Schema::create('ai_benchmark_cases', function (Blueprint $table) {
            $table->id();

            $table->string('domain_key')->nullable()->comment('legal, health, education, code, marketing, general_info أو null لحالة عامة');
            $table->string('country_code', 2)->nullable();
            $table->string('language', 5)->default('ar');
            $table->string('task_type')->default('qa')->comment('qa, code_generation, summarization, recommendation...الخ');
            $table->string('difficulty')->default('easy')->comment('easy, hard, adversarial, insufficient_info (§19.2)');
            $table->string('risk_level')->default('low')->comment('low, medium, high, critical');

            $table->text('prompt');
            $table->string('expected_behavior')->default('answer')->comment('answer, abstain, ask_clarification');
            $table->json('expected_answer_keywords')->nullable()->comment('كلمات مفتاحية متوقعة في إجابة صحيحة - أساس تقييم correctness الخفيف');
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['domain_key', 'difficulty']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_benchmark_cases');
    }
};
