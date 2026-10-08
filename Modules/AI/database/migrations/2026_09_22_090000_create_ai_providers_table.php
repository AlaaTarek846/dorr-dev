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
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique()
                ->comment('معرف برمجي مميز لمزود الذكاء الاصطناعي (مثال: openai, anthropic, google, groq) - يطابق AiProviderKey enum');

            $table->string('name')->comment('اسم المزود للعرض في لوحة تحكم الأدمن (مثال: OpenAI (ChatGPT))');
            $table->boolean('is_enabled')->default(false)->comment('هل المزود ده مفعّل ومسموح يتستخدم فعلياً دلوقتي؟');
            $table->boolean('is_default')->default(false)->comment('هل ده المزود الافتراضي اللي هيستخدمه الشات لو مفيش تحديد تاني؟ (مزود واحد بس يكون افتراضي)');
            $table->text('api_key')->nullable()->comment('مفتاح الـ API الخاص بالمزود - مخفي من أي API response (hidden على الموديل)');
            $table->string('model')->nullable()->comment('اسم الموديل المستخدم من المزود ده (مثال: gpt-4o-mini, claude-sonnet-4-5)');
            $table->string('base_url')->nullable()->comment('رابط الـ API الأساسي للمزود - لو فاضي بياخد الافتراضي من config/ai.php');
            $table->decimal('temperature', 3, 2)->nullable()->comment('درجة العشوائية/الإبداع في الرد (0 = ثابت ومحدد، 2 = متنوع ومبدع)');
            $table->unsignedInteger('max_tokens')->nullable()->comment('أقصى عدد توكنز مسموح بيه في رد الموديل - بيتحكم في التكلفة وطول الرد');
            $table->json('extra')->nullable()->comment('إعدادات إضافية خاصة بالمزود مش محتاجة عمود منفصل (مرونة بدون تعديل الجدول)');
            $table->json('available_models')->nullable()->comment('قائمة الموديلات الحقيقية المتاحة - بتتجاب من المزود نفسه بعد أول اختبار اتصال ناجح');
            $table->timestamp('available_models_synced_at')->nullable()->comment('آخر وقت اتجابت فيه قائمة الموديلات الحقيقية من المزود');
            $table->string('last_test_status')->nullable()->comment('نتيجة آخر اختبار اتصال (success / failed)');
            $table->text('last_test_message')->nullable()->comment('رسالة توضيحية لآخر اختبار اتصال (سبب الفشل مثلاً)');
            $table->timestamp('last_tested_at')->nullable()->comment('وقت آخر اختبار اتصال تم تنفيذه');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
