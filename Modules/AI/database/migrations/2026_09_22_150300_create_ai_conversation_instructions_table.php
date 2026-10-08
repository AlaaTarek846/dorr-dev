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
        Schema::create('ai_conversation_instructions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('ai_conversations')
                ->cascadeOnDelete()
                ->comment('المحادثة اللي التعليمة دي خاصة بيها');

            $table->string('source_type')->default('user')
                ->comment('مصدر التعليمة: user (كتبها اليوزر بنفسه) / system (اتحطت تلقائياً من النظام) / template (قالب جاهز اختاره اليوزر)');

            $table->text('instruction')->comment('نص التعليمة نفسه - مثل "جاوب بالعربي وخلي الشرح مختصر"');

            $table->unsignedInteger('priority')->default(0)
                ->comment('أولوية التعليمة لو فيه تعليمات متعارضة - الأعلى رقم بياخد أولوية أكبر');

            $table->boolean('is_active')->default(true)
                ->comment('هل التعليمة دي فعالة دلوقتي وبتتطبق على الرسايل الجديدة؟');

            $table->timestamps();

            $table->index(['conversation_id', 'is_active'], 'ai_conversation_instructions_conversation_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_instructions');
    }
};
