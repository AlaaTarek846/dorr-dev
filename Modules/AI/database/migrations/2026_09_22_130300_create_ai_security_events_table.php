<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_security_events', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد لحدث الأمان والخصوصية.');

            $table->string('owner_type')->nullable()->comment('نوع صاحب الحدث: user أو provider (Polymorphic).');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('معرف صاحب الحدث (User أو Provider)، إن وجد.');
            $table->index(['owner_type', 'owner_id'], 'ai_security_events_owner_type_owner_id_index');

            $table->string('event_type')->comment('نوع الحدث الأمني، مثل: unauthorized_access, tenant_isolation_breach_attempt, rate_limit_exceeded.');
            $table->string('severity')->default('medium')->comment('درجة خطورة الحدث: low, medium, high, critical.');
            $table->string('correlation_id')->nullable()->comment('معرف موحد لربط هذا الحدث بكل الطلبات المتعلقة به عبر النظام.');
            $table->text('description')->nullable()->comment('وصف تفصيلي للحدث - بيانات تدقيق داخلية للأدمن فقط.');
            $table->timestamps();

            $table->index(['event_type'], 'ai_security_events_event_type_index');
            $table->index(['correlation_id'], 'ai_security_events_correlation_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_security_events');
    }
};
