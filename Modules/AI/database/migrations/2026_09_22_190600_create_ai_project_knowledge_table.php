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
        Schema::create('ai_project_knowledge', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('ai_projects')->cascadeOnDelete()
                ->comment('المشروع اللي مصدر المعرفة ده مربوط بيه');
            $table->foreignId('knowledge_source_id')->constrained('ai_knowledge_sources')->cascadeOnDelete()
                ->comment('مصدر المعرفة المربوط بالمشروع (بدون تكرار بيانات المصدر الأصلية - Reuse من Phase 11)');

            $table->timestamps();

            $table->unique(['project_id', 'knowledge_source_id'], 'ai_project_knowledge_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_project_knowledge');
    }
};
