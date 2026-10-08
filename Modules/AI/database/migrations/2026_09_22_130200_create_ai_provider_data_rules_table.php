<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_data_rules', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد لقاعدة بيانات المزود.');
            $table->foreignId('provider_id')
                ->constrained('ai_providers')
                ->cascadeOnDelete()
                ->comment('المزود الذي تنطبق عليه هذه القاعدة - يتم حذف القاعدة تلقائياً عند حذف المزود.');
            $table->boolean('sanitize_pii')->default(true)->comment('هل يتم تنظيف البيانات الشخصية (PII) مثل الإيميل والتليفون قبل إرسالها لهذا المزود.');
            $table->boolean('sanitize_secrets')->default(true)->comment('هل يتم تنظيف الأسرار (مثل المفاتيح أو كلمات المرور) قبل إرسالها لهذا المزود.');
            $table->json('transformation_rules')->nullable()->comment('قواعد التحويل التفصيلية بصيغة JSON، مثل تمويه (mask) الإيميل والتليفون قبل الإرسال.');
            $table->boolean('is_active')->default(true)->comment('هل القاعدة مفعّلة حالياً.');
            $table->timestamps();

            $table->unique(['provider_id'], 'ai_provider_data_rules_provider_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_data_rules');
    }
};
