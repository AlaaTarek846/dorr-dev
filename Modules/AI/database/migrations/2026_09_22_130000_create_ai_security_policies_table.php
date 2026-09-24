<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_security_policies', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد لسياسة الأمان والخصوصية.');
            $table->string('name')->comment('اسم السياسة (مثال: السياسة الافتراضية لعزل المستأجرين).');
            $table->boolean('authentication_required')->default(true)->comment('هل يلزم تسجيل الدخول (Authentication) قبل تنفيذ أي طلب AI.');
            $table->boolean('authorization_required')->default(true)->comment('هل يلزم التحقق من الصلاحيات (Authorization) قبل تنفيذ الطلب.');
            $table->boolean('tenant_isolation_required')->default(true)->comment('هل يلزم عزل بيانات كل مستأجر (مؤسسة/يوزر) عن باقي المستأجرين - يمنع مستأجر يشوف بيانات مستأجر تاني.');
            $table->boolean('rate_limit_enabled')->default(true)->comment('هل يتم تفعيل تحديد عدد الطلبات المسموح بها فى فترة زمنية معينة لهذه السياسة.');
            $table->text('description')->nullable()->comment('وصف تفصيلي للسياسة والغرض منها.');
            $table->boolean('is_active')->default(true)->comment('هل السياسة مفعّلة حالياً ويتم تطبيقها.');
            $table->timestamps();

            $table->index(['is_active'], 'ai_security_policies_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_security_policies');
    }
};
