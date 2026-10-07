<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_data_policies', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد لسياسة البيانات.');
            $table->string('name')->comment('اسم سياسة البيانات (مثال: سياسة بيانات العملاء الشخصية).');
            $table->string('data_classification')->default('internal')->comment('تصنيف البيانات: public, internal, confidential, personal, secret.');
            $table->unsignedInteger('retention_days')->nullable()->comment('عدد الأيام التي تُخزَّن فيها البيانات قبل حذفها تلقائياً - فارغ يعني بلا حد زمني محدد.');
            $table->boolean('consent_required')->default(false)->comment('هل يلزم الحصول على موافقة صريحة من اليوزر قبل معالجة هذا النوع من البيانات.');
            $table->boolean('minimization_enabled')->default(true)->comment('هل يتم تفعيل مبدأ تقليل البيانات - لا يُرسل للمزود الخارجي إلا البيانات المطلوبة بالظبط.');
            $table->boolean('external_provider_allowed')->default(true)->comment('هل يُسمح أصلاً بإرسال هذا النوع من البيانات لمزود ذكاء اصطناعي خارجي.');
            $table->text('description')->nullable()->comment('وصف تفصيلي للسياسة والغرض منها.');
            $table->boolean('is_active')->default(true)->comment('هل السياسة مفعّلة حالياً ويتم تطبيقها.');
            $table->timestamps();

            $table->index(['data_classification'], 'ai_data_policies_data_classification_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_data_policies');
    }
};
