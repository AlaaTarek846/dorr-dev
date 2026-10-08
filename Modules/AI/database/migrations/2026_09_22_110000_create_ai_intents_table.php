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
        Schema::create('ai_intents', function (Blueprint $table) {
            $table->id();

            // كود مميز لنوع الطلب يستخدمه الكود (مثال: chat, code_generation, research, support)
            $table->string('key')->unique()->comment('كود مميز لنوع الطلب يستخدمه الكود (مثال: chat, code_generation, research, support)');

            // اسم نوع الطلب يظهر للأدمن في لوحة التحكم
            $table->string('name')->comment('اسم نوع الطلب يظهر للأدمن في لوحة التحكم');

            // وصف مختصر يوضح الفرق بين النوع ده وغيره
            $table->text('description')->nullable()->comment('وصف مختصر يوضح الفرق بين النوع ده وغيره');

            // هل النوع ده مفعّل ومسموح النظام يصنّف طلبات عليه دلوقتي؟
            $table->boolean('is_active')->default(true)->comment('هل النوع ده مفعّل ومسموح النظام يصنّف طلبات عليه دلوقتي؟');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_intents');
    }
};
