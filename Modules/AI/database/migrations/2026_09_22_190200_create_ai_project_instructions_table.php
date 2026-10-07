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
        Schema::create('ai_project_instructions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('ai_projects')->cascadeOnDelete()
                ->comment('المشروع اللي التعليمات دي ثابتة عليه');

            $table->text('instruction')->comment('نص التعليمة الثابتة اللي بتتطبق على كل محادثة جوه المشروع (مثال: اتبع دائماً الـ architecture المعتمد)');
            $table->unsignedInteger('priority')->default(0)->comment('أولوية تطبيق التعليمة (رقم أعلى = أولوية أعلى)');
            $table->boolean('is_active')->default(true)->comment('هل التعليمة دي مفعّلة حالياً ولا لأ');

            $table->timestamps();

            $table->index('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_project_instructions');
    }
};
