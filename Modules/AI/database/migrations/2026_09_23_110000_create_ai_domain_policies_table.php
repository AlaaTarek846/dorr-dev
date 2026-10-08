<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.0 requirements doc §7-14: one admin-configurable policy row per
     * domain (legal, health, education, code, marketing, general_info).
     * Each domain pipeline reads its own row instead of hard-coding its
     * rules in application code, so an admin can tune disclaimers /
     * risk handling without a deployment.
     */
    public function up(): void
    {
        Schema::create('ai_domain_policies', function (Blueprint $table) {
            $table->id();

            $table->string('domain_key')->unique()
                ->comment('legal, health, education, code, marketing, general_info');
            $table->string('name')->comment('اسم المسار يظهر للأدمن في لوحة التحكم');
            $table->text('description')->nullable();

            $table->string('risk_level')->default('medium')
                ->comment('low, medium, high, critical - يوضح مدى حساسية هذا المسار');

            $table->boolean('requires_jurisdiction')->default(false)
                ->comment('المسار القانوني: يتطلب تحديد الدولة/الاختصاص قبل إجابة حساسة (§7.1)');
            $table->boolean('requires_triage')->default(false)
                ->comment('المسار الصحي: يتطلب فحص مؤشرات الحالات العاجلة قبل أي رد (§8.1)');
            $table->boolean('sandbox_required')->default(false)
                ->comment('المسار البرمجي: الكود يجب أن ينفذ فعليًا داخل Sandbox قبل اعتباره ناجحًا (§10.3)');
            $table->boolean('allowlist_enforced')->default(false)
                ->comment('يقيّد الاسترجاع من قاعدة المعرفة على مصادر هذا المجال المعتمدة فقط (§5.5, §7.2, §8.2)');

            $table->text('system_prompt_addition')->nullable()
                ->comment('تعليمة نظام إضافية تُلحق برسالة النظام عند تصنيف الطلب على هذا المسار');
            $table->text('disclaimer_text')->nullable()
                ->comment('نص تنويه يُعرض لليوزر (مثال: "راجع محامٍ/طبيب مختص")');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_domain_policies');
    }
};
