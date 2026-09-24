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
        Schema::create('ai_languages', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique()->comment('كود اللغة (ar, en...) - مستقل عن جدول languages العام الخاص بالموقع/الداشبورد');
            $table->string('name')->comment('اسم اللغة للعرض في لوحة تحكم الأدمن');
            $table->enum('direction', ['ltr', 'rtl'])->default('ltr')->comment('اتجاه الكتابة - مهم جداً للفرونت عشان يعرف يحط اتجاه العربي من اليمين للشمال');
            $table->boolean('is_active')->default(true)->comment('هل اللغة دي مفعّلة ومتاحة للاستخدام مع الـ AI؟');

            $table->timestamps();

            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_languages');
    }
};
