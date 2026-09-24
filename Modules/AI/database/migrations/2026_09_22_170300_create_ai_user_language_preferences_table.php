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
        Schema::create('ai_user_language_preferences', function (Blueprint $table) {
            $table->id();

            // صاحب التفضيل: ممكن يكون يوزر أو مقدم خدمة (alias قصير - شوف AIServiceProvider::boot)
            $table->string('owner_type')->comment('نوع صاحب التفضيل (user أو provider)');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب التفضيل في جدول الـ users أو الـ providers حسب owner_type');

            $table->foreignId('language_id')->nullable()
                ->constrained('ai_languages')
                ->nullOnDelete()
                ->comment('اللغة المفضلة لدى اليوزر');

            $table->foreignId('variant_id')->nullable()
                ->constrained('ai_language_variants')
                ->nullOnDelete()
                ->comment('اللهجة/الأسلوب المفضل لدى اليوزر');

            $table->boolean('auto_detect')->default(true)
                ->comment('هل يكتشف النظام لغة الرد أوتوماتيكياً من نص رسالة اليوزر؟');

            $table->string('response_language_mode')->default('follow_input')
                ->comment('طريقة تحديد لغة الرد: follow_input (رد بنفس لغة السؤال) / fixed (لغة ثابتة محددة مسبقاً)');

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id'], 'ai_user_language_preferences_owner_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_user_language_preferences');
    }
};
