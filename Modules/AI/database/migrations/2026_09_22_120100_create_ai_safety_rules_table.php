<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_safety_rules', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد لقاعدة الأمان.');
            $table->foreignId('safety_policy_id')
                ->constrained('ai_safety_policies')
                ->cascadeOnDelete()
                ->comment('سياسة الأمان التي تتبع لها هذه القاعدة - يتم حذف القاعدة تلقائياً عند حذف السياسة.');
            $table->string('name')->comment('اسم القاعدة (مثال: كشف طلب حذف ملفات جماعي).');
            $table->json('condition')->nullable()->comment('شرط تطبيق القاعدة بصيغة JSON (متى تنطبق هذه القاعدة على الطلب).');
            $table->string('action')->default('review')->comment('الإجراء عند تحقق الشرط: allow, block, review, require_confirmation, sanitize.');
            $table->unsignedInteger('priority')->default(0)->comment('أولوية تنفيذ القاعدة عند تعارضها مع قواعد أخرى - الأعلى رقماً يُطبّق أولاً.');
            $table->boolean('is_active')->default(true)->comment('هل القاعدة مفعّلة حالياً.');
            $table->timestamps();

            $table->index(['safety_policy_id', 'priority'], 'ai_safety_rules_policy_priority_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_safety_rules');
    }
};
