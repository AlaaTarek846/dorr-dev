<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_safety_policies', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد لسياسة الأمان.');
            $table->string('name')->comment('اسم سياسة الأمان (مثال: منع محاولات الاختراق).');
            $table->string('risk_level')->default('medium')->comment('درجة الخطورة: low, medium, high, critical.');
            $table->json('applies_to')->nullable()->comment('السياقات التي تنطبق عليها السياسة كـ JSON، مثل: chat, agent, tool, code.');
            $table->text('description')->nullable()->comment('وصف تفصيلي للسياسة والغرض منها.');
            $table->boolean('is_active')->default(true)->comment('هل السياسة مفعّلة حالياً ويتم تطبيقها على الطلبات.');
            $table->timestamps();

            $table->index(['is_active'], 'ai_safety_policies_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_safety_policies');
    }
};
