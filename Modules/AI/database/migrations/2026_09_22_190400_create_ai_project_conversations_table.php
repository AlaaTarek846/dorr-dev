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
        Schema::create('ai_project_conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('ai_projects')->cascadeOnDelete()
                ->comment('المشروع اللي المحادثة دي مربوطة بيه');
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete()
                ->comment('المحادثة المربوطة بالمشروع (بدون تكرار بيانات المحادثة الأصلية - Reuse من Phase 8)');

            $table->timestamps();

            $table->unique(['project_id', 'conversation_id'], 'ai_project_conversations_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_project_conversations');
    }
};
