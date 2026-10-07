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
        Schema::create('ai_workspaces', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('ai_projects')->cascadeOnDelete()
                ->comment('المشروع اللي مساحة العمل دي جزء منه');

            $table->string('name')->comment('اسم مساحة العمل (مثال: Backend Workspace)');
            $table->text('description')->nullable()->comment('وصف مختصر لمساحة العمل وهدفها داخل المشروع');

            $table->boolean('is_active')->default(true)->comment('هل مساحة العمل دي نشطة ولا لأ');

            $table->timestamps();

            $table->index('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_workspaces');
    }
};
