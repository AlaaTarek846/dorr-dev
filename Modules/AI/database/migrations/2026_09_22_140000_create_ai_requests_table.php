<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد للطلب.');

            $table->string('owner_type')->comment('نوع صاحب الطلب: user أو provider (Polymorphic).');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب الطلب (User أو Provider).');
            $table->index(['owner_type', 'owner_id'], 'ai_requests_owner_type_owner_id_index');

            $table->foreignId('gateway_id')
                ->nullable()
                ->constrained('ai_gateways')
                ->nullOnDelete()
                ->comment('البوابة التي استقبلت الطلب.');
            $table->foreignId('intent_id')
                ->nullable()
                ->constrained('ai_intents')
                ->nullOnDelete()
                ->comment('نوع الطلب (Intent) الذي تم تصنيفه له.');
            $table->foreignId('provider_id')
                ->nullable()
                ->constrained('ai_providers')
                ->nullOnDelete()
                ->comment('المزود الذي تم اختياره لتنفيذ الطلب.');

            // عمود نصي بدلاً من foreign key لجدول ai_models لأن هذا الجدول غير موجود فى هذا المشروع -
            // إعدادات الموديلات مخزنة داخل ai_providers.available_models بدلاً من جدول منفصل.
            $table->string('model_key')->nullable()->comment('مفتاح/اسم الموديل الذي تم استخدامه فعلياً لتنفيذ الطلب.');

            $table->longText('prompt')->nullable()->comment('نص الطلب الأصلي المُرسل من اليوزر أو مقدم الخدمة.');
            $table->string('correlation_id')->nullable()->comment('معرف موحد لتتبع هذا الطلب عبر كل الأنظمة الفرعية (Safety, Security, Provider Logs...).');
            $table->string('idempotency_key')->nullable()->comment('مفتاح يمنع تنفيذ نفس الطلب مرتين لو تكرر إرساله بالغلط (مثلاً بسبب انقطاع الشبكة).');

            $table->unsignedInteger('retry_count')->default(0)->comment('عدد مرات إعادة محاولة تنفيذ الطلب عند الفشل.');
            $table->string('error_code')->nullable()->comment('كود الخطأ لو فشل الطلب.');
            $table->text('error_message')->nullable()->comment('رسالة الخطأ التفصيلية لو فشل الطلب.');

            $table->string('status')->default('pending')->comment('حالة الطلب: pending, processing, completed, failed, cancelled, blocked, retrying.');
            $table->timestamps();

            $table->unique(['idempotency_key'], 'ai_requests_idempotency_key_unique');
            $table->index(['correlation_id'], 'ai_requests_correlation_id_index');
            $table->index(['status'], 'ai_requests_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
