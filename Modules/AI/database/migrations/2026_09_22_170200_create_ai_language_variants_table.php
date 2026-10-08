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
        Schema::create('ai_language_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('language_id')
                ->constrained('ai_languages')
                ->cascadeOnDelete()
                ->comment('اللغة اللي اللهجة/الأسلوب ده متفرع منها');

            $table->string('code')->comment('كود اللهجة (msa, egyptian_arabic, gulf_arabic...) - فريد جوا نفس اللغة');
            $table->string('name')->comment('اسم اللهجة/الأسلوب للعرض (مثال: العربية الفصحى، اللهجة المصرية)');
            $table->string('style')->default('formal')->comment('أسلوب التجاوب الافتراضي لهذه اللهجة: formal (رسمي) / conversational (عامي)');
            $table->boolean('is_default')->default(false)->comment('هل ده الـ variant الافتراضي لهذه اللغة لو اليوزر ماحددش لهجة معينة؟');
            $table->boolean('is_active')->default(true)->comment('هل اللهجة دي مفعّلة ومتاحة للاستخدام؟');

            $table->timestamps();

            $table->unique(['language_id', 'code'], 'ai_language_variants_language_code_unique');
            $table->index(['language_id', 'is_active'], 'ai_language_variants_language_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_language_variants');
    }
};
