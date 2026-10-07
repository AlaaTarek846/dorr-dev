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
        Schema::create('ai_provider_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_id')->nullable()
                ->constrained('ai_providers')
                ->nullOnDelete()
                ->comment('مزود الـ AI اللي اتكلم معاه النظام في المحاولة دي');

            $table->unsignedBigInteger('request_id')->nullable()
                ->comment('طلب الـ AI اللي المحاولة دي جزء منه (لو موجود)');

            $table->foreign('request_id')
                ->references('id')->on('ai_requests')
                ->nullOnDelete();

            $table->string('correlation_id')->nullable()
                ->comment('معرف موحد لتجميع كل المحاولات المرتبطة بطلب واحد عبر الأنظمة الفرعية');

            $table->unsignedSmallInteger('http_status')->nullable()
                ->comment('كود الرد HTTP اللي رجع من المزود (200, 429, 500...الخ)');

            $table->unsignedInteger('response_time_ms')->nullable()
                ->comment('زمن استجابة المزود بالميلي ثانية');

            $table->string('error_code')->nullable()->comment('كود الخطأ لو المحاولة فشلت');
            $table->text('error_message')->nullable()->comment('نص الخطأ التفصيلي لو المحاولة فشلت');

            $table->timestamps();

            $table->index(['provider_id', 'created_at'], 'ai_provider_logs_provider_created_index');
            $table->index('correlation_id', 'ai_provider_logs_correlation_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provider_logs');
    }
};
