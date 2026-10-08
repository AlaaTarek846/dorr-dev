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
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();

            // صاحب المحادثة: ممكن يكون يوزر أو مقدم خدمة - الشات مشترك بين الاتنين
            $table->string('owner_type')->comment('نوع صاحب المحادثة (alias قصير: user أو provider - شوف AIServiceProvider::boot)');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب المحادثة في جدول الـ users أو الـ providers حسب owner_type');

            $table->string('title')->nullable()->comment('عنوان المحادثة - بيتحسب تلقائياً من أول رسالة لو اليوزر مسماش المحادثة بنفسه');
            $table->string('provider_key')->nullable()->comment('آخر مزود AI اتستخدم في المحادثة دي (openai/anthropic/google/groq)');

            $table->timestamps();

            $table->index(['owner_type', 'owner_id'], 'ai_conversations_owner_type_owner_id_index');
            $table->index(['owner_type', 'owner_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('ai_conversations')
                ->cascadeOnDelete()
                ->comment('المحادثة اللي الرسالة دي جزء منها');

            $table->string('role')->comment('مين بعت الرسالة: user (اليوزر) / assistant (رد الـ AI) / system (تعليمات خلفية مش بتتعرض)');
            $table->longText('content')->comment('نص الرسالة الفعلي');
            $table->string('provider_key')->nullable()->comment('مزود الـ AI اللي رد على الرسالة دي (فاضي لرسائل اليوزر نفسه)');
            $table->string('model')->nullable()->comment('اسم الموديل اللي فعلياً رد بالرسالة دي');
            $table->unsignedInteger('tokens_used')->nullable()->comment('عدد التوكنز المستهلكة في الرد ده - لحساب التكلفة/الاستهلاك لاحقاً');
            $table->boolean('is_error')->default(false)->comment('هل الرسالة دي فعلياً رسالة خطأ (فشل الاتصال بالمزود) مش رد حقيقي من الـ AI؟');

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
