<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-150a: the Workroom's tasks (docs/modules/freelance/TEAMS-ERD.md, section 3). One task belongs to an agreement, optionally to one of its
 * parts, and is the responsibility of one side (client or freelancer). `scope` is `shared` for now (both sides see it); `team` arrives with
 * the freelancer teams. A deleted task is kept (soft delete) because the project record points at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_workroom_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('freelance_milestones')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('status', 12)->default('todo')->comment('todo | doing | done | blocked');
            $table->date('due_on')->nullable();
            $table->string('scope', 10)->default('shared')->comment('shared | team (later)');
            $table->string('assignee_side', 10)->comment('client | freelancer: the side responsible for it');
            $table->string('created_by_side', 10)->comment('client | freelancer');
            $table->unsignedBigInteger('created_by_id')->comment('the client user or the freelancer');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agreement_id', 'status']);
            $table->index(['project_id', 'id']);
            $table->index('milestone_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_workroom_tasks');
    }
};
