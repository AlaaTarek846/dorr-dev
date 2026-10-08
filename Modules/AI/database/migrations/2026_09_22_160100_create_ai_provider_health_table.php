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
        Schema::create('ai_provider_health', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_id')
                ->constrained('ai_providers')
                ->cascadeOnDelete()
                ->comment('مزود الـ AI اللي الفحص ده بتاعه');

            $table->string('check_type')->default('connectivity')
                ->comment('نوع الفحص: connectivity (اتصال) / latency (زمن استجابة) / availability (توافر) / functional (وظيفي)');

            $table->decimal('health_score', 3, 2)->default(1)
                ->comment('رقم من 0 لـ 1 يلخص صحة المزود (سرعة + نسبة نجاح) - طبقة التوجيه بتبص عليه قبل ما تختار المزود');

            $table->string('status')->default('healthy')
                ->comment('حالة المزود: healthy (سليم) / degraded (متدهور) / unhealthy (غير صالح - مايتختارش) / recovering (بيتعافى)');

            $table->json('details')->nullable()
                ->comment('تفاصيل إضافية عن الفحص (مثل نسبة النجاح، متوسط زمن الاستجابة)');

            $table->timestamps();

            $table->index(['provider_id', 'status'], 'ai_provider_health_provider_status_index');
            $table->index(['provider_id', 'created_at'], 'ai_provider_health_provider_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provider_health');
    }
};
