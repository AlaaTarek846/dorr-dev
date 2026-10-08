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
        Schema::create('ai_project_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('ai_projects')->cascadeOnDelete()
                ->comment('المشروع اللي الملف ده مربوط بيه');
            $table->foreignId('file_id')->constrained('ai_files')->cascadeOnDelete()
                ->comment('الملف المربوط بالمشروع (بدون تكرار بيانات الملف الأصلية - Reuse من Phase 11)');

            $table->timestamps();

            $table->unique(['project_id', 'file_id'], 'ai_project_files_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_project_files');
    }
};
