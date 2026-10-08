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
        Schema::create('ai_locales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('language_id')
                ->constrained('ai_languages')
                ->cascadeOnDelete()
                ->comment('اللغة اللي المنطقة دي مرتبطة بيها');

            $table->string('code')->unique()->comment('كود المنطقة الجغرافية (ar-SA, ar-EG, en-US...)');
            $table->string('name')->comment('اسم المنطقة للعرض في لوحة تحكم الأدمن');
            $table->json('settings')->nullable()->comment('إعدادات شكل التاريخ والأرقام الخاصة بالمنطقة دي');
            $table->boolean('is_active')->default(true)->comment('هل المنطقة دي مفعّلة ومتاحة للاستخدام؟');

            $table->timestamps();

            $table->index(['language_id', 'is_active'], 'ai_locales_language_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_locales');
    }
};
