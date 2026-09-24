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
        Schema::create('ai_routing_policies', function (Blueprint $table) {
            $table->id();

            // اسم السياسة للعرض في لوحة تحكم الأدمن
            $table->string('name')->comment('اسم السياسة للعرض في لوحة تحكم الأدمن');

            // نطاق تطبيق السياسة: على كل الطلبات (global) أو مقيدة ببلد/خدمة/خطة/شرط مخصص
            $table->string('scope_type')->default('global')
                ->comment('نطاق تطبيق السياسة: global / country / service / plan / custom');

            // كود البلد لو السياسة مقيدة ببلد معين (مطلوب لو scope_type = country)
            $table->string('country_code', 2)->nullable()->comment('كود البلد لو السياسة مقيدة ببلد معين (مطلوب لو scope_type = country)');

            // كود الخدمة لو السياسة مقيدة بخدمة معينة (مطلوب لو scope_type = service)
            $table->string('service_key')->nullable()->comment('كود الخدمة لو السياسة مقيدة بخدمة معينة (مطلوب لو scope_type = service)');

            // الخطة لو السياسة مقيدة بخطة اشتراك معينة (مطلوب لو scope_type = plan)
            $table->foreignId('plan_id')->nullable()->constrained('ai_plans')->nullOnDelete()
                ->comment('الخطة لو السياسة مقيدة بخطة اشتراك معينة (مطلوب لو scope_type = plan)');

            // إزاي النظام يختار المزود/الموديل: priority (أولوية ثابتة) / quality / speed / cost / availability / weighted (مزيج موزون)
            $table->string('selection_strategy')->default('priority')
                ->comment('إزاي النظام يختار المزود/الموديل: priority / quality / speed / cost / availability / weighted');

            // هل مسموح النظام يروح لمزود بديل تلقائياً لو المزود الأساسي فشل؟
            $table->boolean('fallback_enabled')->default(true)->comment('هل مسموح النظام يروح لمزود بديل تلقائياً لو المزود الأساسي فشل؟');

            // لو فيه أكتر من سياسة ممكن تنطبق على نفس الطلب، الرقم الأعلى بيفوز
            $table->unsignedInteger('priority')->default(0)->comment('لو فيه أكتر من سياسة ممكن تنطبق على نفس الطلب، الرقم الأعلى بيفوز');

            // هل السياسة دي مفعّلة ومسموح تتطبق دلوقتي؟
            $table->boolean('is_active')->default(true)->comment('هل السياسة دي مفعّلة ومسموح تتطبق دلوقتي؟');

            $table->timestamps();

            $table->index(['scope_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_routing_policies');
    }
};
