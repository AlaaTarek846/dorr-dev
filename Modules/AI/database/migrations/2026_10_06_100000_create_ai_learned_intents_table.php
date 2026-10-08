<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The self-growing half of the chat's intent dictionary.
 *
 * AiChatLexicon is a static, hand-written dictionary. When it does not
 * understand a message, AiIntentRouterService asks the default model once;
 * when the model is confident, the words that expressed the request are
 * stored here, so the SAME kind of request is understood for free (no
 * model call) from then on.
 *
 * Only the short trigger phrase (or a short, PII-free whole message) is
 * stored - never a long conversation excerpt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_learned_intents', function (Blueprint $table) {
            $table->id();

            $table->string('phrase', 120)->comment('العبارة بعد التطبيع (ArabicTextNormalizer)');
            $table->string('match_mode', 10)->default('phrase')->comment('phrase = العبارة موجودة داخل الرسالة | exact = الرسالة كلها تساوي العبارة');
            $table->string('intent', 40)->comment('واحد من AiChatIntent::ACTIONS');
            $table->string('file_format', 10)->nullable()->comment('pdf|docx|xlsx - لنية file_output فقط');
            $table->string('language', 8)->default('mixed')->comment('ar|en|mixed');

            $table->decimal('confidence', 4, 3)->default(0)->comment('أعلى ثقة سجّلها الموديل');
            $table->unsignedInteger('confirmations')->default(1)->comment('كام مرة الموديل أكّد نفس العبارة/النية');
            $table->unsignedInteger('conflicts')->default(0)->comment('كام مرة الموديل رجّع نية مختلفة لنفس العبارة - لو > 0 ما بتتفعّلش تلقائياً');
            $table->unsignedInteger('hits')->default(0)->comment('كام مرة اتستخدمت بدل سؤال الموديل');
            $table->timestamp('last_hit_at')->nullable();

            $table->boolean('is_active')->default(false)->comment('فقط الصفوف النشطة بتتطابق');
            $table->string('source', 20)->default('model')->comment('model|admin');
            $table->string('learned_with_model', 120)->nullable();

            $table->timestamps();

            $table->unique(['phrase', 'intent', 'match_mode'], 'ai_learned_intents_unique');
            $table->index(['is_active', 'match_mode'], 'ai_learned_intents_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_learned_intents');
    }
};
