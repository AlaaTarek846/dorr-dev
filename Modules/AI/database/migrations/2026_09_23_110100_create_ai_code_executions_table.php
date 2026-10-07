<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.0 requirements doc §10.3/§17.5: every code-domain reply that
     * claims runnable code must be backed by a real execution record
     * inside an isolated Sandbox - never just the model's own claim
     * that the code works.
     */
    public function up(): void
    {
        Schema::create('ai_code_executions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('request_id')->nullable()
                ->comment('طلب الـ AI (ai_requests) اللي الكود ده جزء من الرد عليه، إن وجد');
            $table->foreign('request_id')->references('id')->on('ai_requests')->nullOnDelete();

            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->nullOnDelete();

            $table->string('language')->comment('php, node, python...الخ');
            $table->string('driver')->comment('اسم الـ driver اللي نفذ الكود فعليًا (مثال: docker)');

            $table->text('code')->comment('الكود اللي اتنفذ فعليًا - نسخة طبق الأصل مما تم إرساله للـ Sandbox');

            $table->string('status')->default('pending')
                ->comment('pending, completed, failed, timeout, unavailable');
            $table->integer('exit_code')->nullable();
            $table->text('stdout')->nullable();
            $table->text('stderr')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('attempt_number')->default(1)
                ->comment('رقم محاولة التصحيح الحالية (§10.4: يعاد التنفيذ بعد فشل حتى النجاح أو الحد الأقصى)');

            $table->timestamps();

            $table->index(['request_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_code_executions');
    }
};
