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
        Schema::create('ai_project_context', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('ai_projects')->cascadeOnDelete()
                ->comment('المشروع اللي عنصر الذاكرة ده تابع له');

            $table->string('context_type')->default('decision')
                ->comment('نوع عنصر الذاكرة طويلة الأمد: decision (قرار) / constraint (قيد) / summary (ملخص)');
            $table->text('content')->comment('محتوى عنصر الذاكرة (نص القرار أو القيد أو الملخص)');

            $table->timestamps();

            $table->index('project_id');
            $table->index('context_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_project_context');
    }
};
