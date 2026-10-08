<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per image/video the chat generates. It serves two jobs: the daily
     * quota (today's rows that did not fail) and, for video, the state of the
     * long-running provider job that finishes after the chat reply was sent.
     */
    public function up(): void
    {
        Schema::create('ai_media_generations', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type')->comment('نوع صاحب الطلب: user أو provider');
            $table->unsignedBigInteger('owner_id');

            $table->string('kind', 16)->comment('image أو video');
            $table->string('status', 24)->default('pending')->comment('pending, processing, completed, failed');

            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('ai_messages')->nullOnDelete()
                ->comment('رسالة المساعد التي سيظهر فيها الناتج');
            $table->foreignId('request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('model_key')->nullable();

            $table->text('prompt')->nullable()->comment('النص بعد إخفاء البيانات الحساسة');
            $table->unsignedInteger('requested_seconds')->nullable()->comment('مدة الفيديو المطلوبة');
            $table->string('size', 32)->nullable()->comment('الدقة/المقاس المطلوب');

            $table->string('provider_job_id')->nullable()->comment('معرّف المهمة عند المزود (للفيديو)');
            $table->unsignedInteger('attempts')->default(0)->comment('عدد مرات سؤال المزود عن الحالة');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'kind', 'created_at'], 'ai_media_generations_quota_index');
            $table->index(['kind', 'status'], 'ai_media_generations_kind_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_media_generations');
    }
};
