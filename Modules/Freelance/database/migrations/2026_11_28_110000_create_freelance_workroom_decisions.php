<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-150c: the Workroom's decision log (docs/modules/freelance/TEAMS-ERD.md, section 3). One side proposes a decision, the other side agrees or
 * declines it; an agreed decision is never changed - a new decision with `supersedes_id` replaces it (the old one becomes `superseded` when the
 * new one is agreed). A decision records what the two sides agreed on a detail; it does not change the agreement's money (that is a change request).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_workroom_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('freelance_milestones')->nullOnDelete();
            $table->string('title', 160);
            $table->text('body');
            $table->string('status', 12)->default('proposed')->comment('proposed | agreed | declined | superseded');
            $table->string('proposed_by_side', 10)->comment('client | freelancer');
            $table->unsignedBigInteger('proposed_by_id')->comment('the client user or the freelancer');
            $table->timestamp('client_ack_at')->nullable();
            $table->timestamp('freelancer_ack_at')->nullable();
            $table->timestamp('answered_at')->nullable()->comment('when it was agreed or declined');
            $table->string('answer_note', 300)->nullable()->comment('what the answering side wrote with its answer');
            $table->foreignId('supersedes_id')->nullable()->constrained('freelance_workroom_decisions')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status', 'id']);
            $table->index('milestone_id');
            $table->index('supersedes_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_workroom_decisions');
    }
};
