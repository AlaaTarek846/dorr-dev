<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-206b: who of the team works on which part of a team's project - and on which task. A person put on a part or a task reaches the
 * project (D9) and the assigned member starts his part; leaving the team lets all of it go (D5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_milestone_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained('freelance_milestones')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_by_freelancer_id')->nullable();
            $table->timestamps();

            $table->unique(['milestone_id', 'freelancer_id'], 'freelance_milestone_assignees_pair_unique');
            $table->index('freelancer_id');
        });

        Schema::table('freelance_workroom_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('assignee_freelancer_id')->nullable()->after('assignee_side');
            $table->index(['assignee_freelancer_id', 'status'], 'freelance_workroom_tasks_assignee_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_workroom_tasks', function (Blueprint $table) {
            $table->dropIndex('freelance_workroom_tasks_assignee_status_index');
            $table->dropColumn('assignee_freelancer_id');
        });
        Schema::dropIfExists('freelance_milestone_assignees');
    }
};
