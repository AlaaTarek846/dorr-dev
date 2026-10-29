<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-208: which freelancers were told about which project (once each), and how well it fitted them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_project_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(0)->comment('how many of the project skills the freelancer has');
            $table->timestamp('created_at')->nullable();

            $table->unique(['project_id', 'freelancer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_project_alerts');
    }
};
