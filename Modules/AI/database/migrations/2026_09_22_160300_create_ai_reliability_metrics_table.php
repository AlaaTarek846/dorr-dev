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
        Schema::create('ai_reliability_metrics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_id')->nullable()
                ->constrained('ai_providers')
                ->nullOnDelete()
                ->comment('المزود اللي المقاييس دي محسوبة عليه');

            // عمود نصي بدلاً من foreign key لجدول ai_models لأن هذا الجدول غير موجود فى هذا المشروع
            $table->string('model_key')->nullable()
                ->comment('كود الموديل اللي المقاييس دي محسوبة عليه (بدون جدول ai_models منفصل)');

            $table->foreignId('intent_id')->nullable()
                ->constrained('ai_intents')
                ->nullOnDelete()
                ->comment('نوع المهمة اللي المقاييس دي محسوبة عليها');

            $table->string('region')->nullable()->comment('المنطقة الجغرافية اللي المقاييس دي محسوبة عليها');

            $table->foreignId('plan_id')->nullable()
                ->constrained('ai_plans')
                ->nullOnDelete()
                ->comment('الخطة اللي المقاييس دي محسوبة عليها');

            $table->timestamp('period_start')->comment('بداية الفترة الزمنية اللي المقاييس دي بتغطيها');
            $table->timestamp('period_end')->comment('نهاية الفترة الزمنية اللي المقاييس دي بتغطيها');

            $table->decimal('success_rate', 5, 2)->default(0)->comment('نسبة الطلبات الناجحة خلال الفترة (%)');
            $table->decimal('error_rate', 5, 2)->default(0)->comment('نسبة الطلبات الفاشلة خلال الفترة (%)');

            $table->unsignedInteger('latency_p50_ms')->nullable()->comment('50% من الطلبات اتجاوبت في أقل من الرقم ده بالميلي ثانية');
            $table->unsignedInteger('latency_p95_ms')->nullable()->comment('95% من الطلبات اتجاوبت في أقل من الرقم ده بالميلي ثانية');
            $table->unsignedInteger('latency_p99_ms')->nullable()->comment('99% من الطلبات اتجاوبت في أقل من الرقم ده بالميلي ثانية');

            $table->timestamps();

            $table->index(['provider_id', 'period_start'], 'ai_reliability_metrics_provider_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_reliability_metrics');
    }
};
