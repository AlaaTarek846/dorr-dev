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
        Schema::create('ai_plans', function (Blueprint $table) {
            $table->id();

            $table->string('name')->comment('اسم الخطة اللي بيظهر لليوزر (مثال: "الخطة الأساسية", "Pro")');
            $table->string('code')->unique()->comment('كود مميز يستخدمه الكود بدل الاسم أو الـ id الرقمي (مثال: pro, trial, enterprise_basic)');
            $table->text('description')->nullable()->comment('وصف مختصر للخطة يظهر في صفحة الأسعار');
            $table->unsignedInteger('usage_minutes')->comment('أقصى عدد دقائق استخدام مسموح بيها في الخطة دي (شهرياً مثلاً)');
            $table->unsignedInteger('cooldown_minutes')->default(0)->comment('لو اليوزر خلص رصيده، لازم يستنى كذا دقيقة قبل ما يبعت طلب تاني - بيمنع إساءة الاستخدام');
            $table->decimal('price', 10, 2)->default(0)->comment('سعر الخطة');
            $table->string('currency', 3)->default('EGP')->comment('كود العملة (مثال: EGP, USD, SAR)');
            $table->boolean('is_trial')->default(false)->comment('هل دي خطة تجريبية مجانية؟ (بترتبط بمنطق ai_trial_control)');
            $table->boolean('is_active')->default(true)->comment('هل الخطة ظاهرة ومتاحة للاشتراك دلوقتي؟');
            $table->unsignedInteger('sort_order')->default(0)->comment('ترتيب عرض الخطة في صفحة الأسعار (الأصغر يظهر الأول)');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_plans');
    }
};
