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
        Schema::create('ai_gateways', function (Blueprint $table) {
            $table->id();

            // اسم الـ Gateway للعرض في لوحة تحكم الأدمن
            $table->string('name')->comment('اسم الـ Gateway للعرض في لوحة تحكم الأدمن');

            // بيئة التشغيل: development / staging / production - بيسمح تختبر سياسات جديدة من غير ما تلمس البروداكشن
            $table->string('environment')->default('production')
                ->comment('بيئة التشغيل: development / staging / production');

            // السياسة الافتراضية اللي تتطبق لو مفيش سياسة أوضح تنطبق على الطلب
            $table->foreignId('default_policy_id')->nullable()->constrained('ai_routing_policies')->nullOnDelete()
                ->comment('السياسة الافتراضية اللي تتطبق لو مفيش سياسة أوضح تنطبق على الطلب');

            // هل الـ Gateway ده شغال ومستقبل طلبات دلوقتي؟
            $table->boolean('is_active')->default(true)->comment('هل الـ Gateway ده شغال ومستقبل طلبات دلوقتي؟');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_gateways');
    }
};
