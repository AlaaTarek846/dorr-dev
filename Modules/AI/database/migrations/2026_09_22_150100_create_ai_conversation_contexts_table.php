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
        Schema::create('ai_conversation_contexts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('ai_conversations')
                ->cascadeOnDelete()
                ->comment('المحادثة اللي القطعة دي من السياق بتاعتها');

            $table->string('context_type')->default('message_history')
                ->comment('نوع قطعة السياق: message_history (تاريخ رسائل) / summary (ملخص) / system_instruction (تعليمة نظام) / attachment (مرفق) / memory (ذاكرة طويلة الأمد) / external (مصدر خارجي)');

            $table->longText('content')->nullable()
                ->comment('محتوى قطعة السياق الفعلي أو مرجع لمكان تخزينه');

            $table->boolean('included')->default(true)
                ->comment('هل القطعة دي متضمنة فعلياً في الـ prompt المرسل للموديل دلوقتي ولا مستبعدة/مؤرشفة؟');

            $table->unsignedInteger('token_count')->default(0)
                ->comment('عدد التوكنز اللي القطعة دي هتاخدها من الـ context window المتاح للموديل');

            $table->timestamps();

            $table->index(['conversation_id', 'included'], 'ai_conversation_contexts_conversation_included_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_contexts');
    }
};
