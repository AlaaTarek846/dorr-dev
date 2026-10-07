<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registry of the real models an admin has actually connected for a
     * given ai_providers row (e.g. gpt-4o, gpt-4o-mini, gpt-image-1 all
     * under the same "openai" provider), each wearing its own set of
     * capability tags (chat/vision/coding/research/...). Before this
     * table existed, ai_providers.model was a single free-text column, so
     * a provider could only ever "be" one model at a time and nothing in
     * the schema recorded what any given model was actually good at -
     * AiRoutingEngine/AiModelSelector read this table to auto-pick the
     * best model for a message instead of always using the provider's
     * single legacy model.
     */
    public function up(): void
    {
        Schema::create('ai_provider_models', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete()
                ->comment('مزود الذكاء الاصطناعي صاحب الموديل ده');

            $table->string('model_key')
                ->comment('اسم الموديل الحقيقي عند المزود (مثال: gpt-4o, gpt-4o-mini, claude-sonnet-4-5)');

            $table->string('display_name')->nullable()
                ->comment('اسم مختصر يظهر للأدمن في الشاشة - لو فاضي بيتعرض model_key نفسه');

            $table->json('capabilities')
                ->comment('قائمة القدرات (JSON) من enum ثابت بالكود: chat, vision, image_generation, document_analysis, coding, research, study, reasoning');

            $table->boolean('is_default')->default(false)
                ->comment('هل ده الموديل الافتراضي للمحادثة النصية العادية عند المزود ده؟ (واحد بس لكل مزود)');

            $table->boolean('is_active')->default(true)
                ->comment('هل الموديل ده مفعّل ومسموح النظام يختاره تلقائياً دلوقتي؟');

            $table->unsignedInteger('sort_order')->default(0)
                ->comment('ترتيب العرض في شاشة الأدمن، وترتيب تفضيل عند تعادل أكتر من موديل بنفس القدرة');

            $table->timestamps();

            $table->unique(['provider_id', 'model_key']);
            $table->index(['provider_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_models');
    }
};
