<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 (realtime voice) - see AiRealtimeService. This table never
     * stores the live audio itself (that goes straight from the Android
     * client to OpenAI over its own WebRTC connection, see
     * OpenAiConnector::createRealtimeSession()'s docblock) - only an
     * audit/usage-tracking record of each minted session: who asked for
     * it, which provider/model answered, and roughly how long the
     * session actually lasted, mirroring how ai_usage_sessions already
     * tracks a text chat window (section 34/35 - "voice limits").
     */
    public function up(): void
    {
        Schema::create('ai_realtime_sessions', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type')->comment('نوع صاحب الجلسة (alias قصير: user أو provider)');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب الجلسة في جدول الـ users أو الـ providers حسب owner_type');

            $table->foreignId('conversation_id')->nullable()
                ->constrained('ai_conversations')
                ->nullOnDelete()
                ->comment('المحادثة اللي الجلسة الصوتية دي مرتبطة بيها، لو موجودة');

            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('model_key')->comment('الموديل اللي اتسجلت الجلسة بيه فعلياً');

            $table->string('provider_session_reference')->nullable()
                ->comment('أي معرف يرجعه المزود نفسه للجلسة (لو موجود) - مش سر الاعتماد نفسه، ده مبيتخزنش خالص');

            $table->string('status')->default('created')
                ->comment('created | active | ended | failed');

            $table->timestamp('expires_at')->nullable()
                ->comment('امتى بينتهي سر الاعتماد المؤقت (client_secret) اللي اتبعت للعميل');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable()
                ->comment('مدة الجلسة الفعلية لو العميل بلّغ بيها عند الإنهاء - لحساب استهلاك دقائق الصوت');

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'created_at']);
            $table->index(['provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_realtime_sessions');
    }
};
