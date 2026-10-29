<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-207: the projects a freelancer saved to come back to. One row per freelancer and project. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_project_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['freelancer_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_project_favorites');
    }
};
