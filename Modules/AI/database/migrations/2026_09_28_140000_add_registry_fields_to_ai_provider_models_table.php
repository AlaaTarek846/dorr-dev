<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business gap fix (dynamic OpenAI model registry): before this,
     * ai_provider_models only ever recorded WHAT a model can do
     * (capabilities), never WHICH kind of thing it is (category) or how
     * it relates to other rows (family/alias/snapshot) or whether it is
     * still alive at the provider (deprecation). That made it impossible
     * to tell a general chat model from a transcription or video model
     * except by re-reading the raw model_key, and made "the model
     * disappeared from OpenAI's list" indistinguishable from "nobody has
     * looked in a while" - both looked identical (just a row that still
     * exists). Every column here is purely additive and nullable/defaulted,
     * so every existing row and every existing query against this table
     * (activeModels(), bestModelFor(), hasAllCapabilities(), the whole
     * capabilities-driven routing/resolver pipeline) keeps working
     * completely unchanged - is_active/is_default/capabilities are left
     * untouched by this migration.
     */
    public function up(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table) {
            $table->string('category')->nullable()->after('capabilities')
                ->comment('تصنيف الموديل (مختلف عن capabilities) من enum AiModelCategory - general, reasoning, coding, image_generation, video_generation, realtime_voice, speech_to_text, text_to_speech, deep_research, cybersecurity, life_sciences, embeddings, moderation, unknown');

            $table->boolean('needs_review')->default(false)
                ->comment('النظام مش واثق من تصنيف الموديل ده (category=unknown) ومحتاج مراجعة يدوية من الأدمن');

            $table->string('model_family')->nullable()
                ->comment('عائلة الموديل الأساسية بدون suffix التاريخ/الإصدار، مثلاً "gpt-5.6" أو "gpt-image-2.5"');

            $table->string('canonical_model_id')->nullable()
                ->comment('لو الصف ده alias أو dated snapshot، ده الـ model_key الأساسي اللي بيتبع له');

            $table->boolean('is_alias')->default(false)
                ->comment('هل model_key ده اسم بديل (alias) لموديل تاني بدل ما يكون موديل مستقل؟');

            $table->boolean('is_snapshot')->default(false)
                ->comment('هل model_key ده نسخة مؤرخة (dated snapshot) من عائلة موديل، مثلاً gpt-5.4-2026-03-05؟');

            $table->date('release_date')->nullable()
                ->comment('تاريخ الإصدار المستخرج من الـ model_key لو كان dated snapshot');

            $table->string('status')->default('active')
                ->comment('active | deprecated | disabled - طبقة أغنى فوق is_active الموجود، الاتنين بيتحدثوا مع بعض دايماً من عند الـ sync service فمفيش كود قديم بينكسر');

            $table->timestamp('deprecated_at')->nullable()
                ->comment('امتى اتعرف إن الموديل ده اختفى من قائمة المزود الحقيقية - بيتسجل مرة واحدة بس، مايتمسحش لو رجع الموديل تاني (last_seen_at هو اللي بيتحدث وقتها)');

            $table->timestamp('last_seen_at')->nullable()
                ->comment('آخر مرة ظهر فيها الموديل ده فعلياً فى نتيجة sync حقيقية من المزود');

            $table->json('endpoints')->nullable()
                ->comment('الـ API endpoints اللي الموديل ده بيشتغل معاها فعلياً (chat_completions, responses, images, audio_transcriptions, audio_speech, videos, realtime) لو المعلومة دي معروفة');

            $table->index(['provider_id', 'category']);
            $table->index(['provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table) {
            $table->dropIndex(['provider_id', 'category']);
            $table->dropIndex(['provider_id', 'status']);

            $table->dropColumn([
                'category', 'needs_review', 'model_family', 'canonical_model_id',
                'is_alias', 'is_snapshot', 'release_date', 'status',
                'deprecated_at', 'last_seen_at', 'endpoints',
            ]);
        });
    }
};
