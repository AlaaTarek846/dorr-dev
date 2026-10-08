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
        Schema::create('ai_failovers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('primary_provider_id')->nullable()
                ->constrained('ai_providers')
                ->nullOnDelete()
                ->comment('المزود الأساسي اللي النظام اضطر يسيبه');

            $table->foreignId('fallback_provider_id')->nullable()
                ->constrained('ai_providers')
                ->nullOnDelete()
                ->comment('المزود البديل اللي النظام راح له بدل الأساسي');

            $table->unsignedBigInteger('request_id')->nullable()
                ->comment('طلب الـ AI اللي الفيل أوفر ده حصل بسببه');

            $table->foreign('request_id')
                ->references('id')->on('ai_requests')
                ->nullOnDelete();

            $table->string('trigger_type')->default('provider_error')
                ->comment('سبب الفيل أوفر: timeout (انتهاء مهلة) / provider_error (خطأ من المزود) / unavailable (غير متاح) / health_threshold (تحت الحد الأدنى للصحة) / rate_limit (تجاوز حد الطلبات)');

            $table->unsignedInteger('attempt_number')->default(1)
                ->comment('رقم المحاولة الحالية لنفس الطلب (لو فشل أكتر من مزود بديل)');

            $table->text('reason')->nullable()->comment('تفاصيل إضافية عن سبب الفيل أوفر');

            $table->timestamps();

            $table->index(['primary_provider_id', 'created_at'], 'ai_failovers_primary_provider_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_failovers');
    }
};
