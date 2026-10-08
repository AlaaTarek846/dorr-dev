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
        Schema::create('ai_routing_rules', function (Blueprint $table) {
            $table->id();

            // السياسة اللي القاعدة دي تابعة لها
            $table->foreignId('routing_policy_id')->constrained('ai_routing_policies')->cascadeOnDelete()
                ->comment('السياسة اللي القاعدة دي تابعة لها');

            // نوع الطلب اللي القاعدة دي تنطبق عليه (فاضي = تنطبق على كل الأنواع)
            $table->foreignId('intent_id')->nullable()->constrained('ai_intents')->nullOnDelete()
                ->comment('نوع الطلب اللي القاعدة دي تنطبق عليه (فاضي = تنطبق على كل الأنواع)');

            // المزود المستهدف لو الشرط اتحقق
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete()
                ->comment('المزود المستهدف لو الشرط اتحقق');

            // اسم الموديل المستهدف عند المزود ده (المشروع الحالي مفيهوش جدول ai_models منفصل - الموديل عمود نصي على ai_providers)
            $table->string('model_key')->nullable()
                ->comment('اسم الموديل المستهدف عند المزود ده - نص حر لأن المشروع الحالي مفيهوش جدول ai_models منفصل');

            // لو فيه أكتر من قاعدة ممكن تنطبق، الرقم الأعلى بيفوز
            $table->unsignedInteger('priority')->default(0)->comment('لو فيه أكتر من قاعدة ممكن تنطبق، الرقم الأعلى بيفوز');

            // تفاصيل إضافية للاختيار (مثل max_latency_ms, min_quality) من غير ما نضيف عمود جديد لكل تفصيلة
            $table->json('selection_config')->nullable()
                ->comment('تفاصيل إضافية للاختيار (مثل max_latency_ms, min_quality) من غير ما نضيف عمود جديد لكل تفصيلة');

            // هل القاعدة دي مفعّلة ومسموح تتطبق دلوقتي؟
            $table->boolean('is_active')->default(true)->comment('هل القاعدة دي مفعّلة ومسموح تتطبق دلوقتي؟');

            $table->timestamps();

            $table->index(['routing_policy_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_routing_rules');
    }
};
