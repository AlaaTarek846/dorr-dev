<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-704 / FL-507 / FL-705: a dispute on one part of the work — who opened it and why, what each side shows, the staff's decision
 * (money back to the client, paid to the freelancer, or split), one appeal decided by another member of staff, and when the money moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_disputes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('milestone_id')->constrained('freelance_milestones')->cascadeOnDelete();
            $table->string('opened_by', 12)->comment('client · freelancer');
            $table->unsignedBigInteger('opener_id');
            $table->string('reason', 40);
            $table->text('details');
            $table->string('milestone_status', 20)->comment('where the part stood when it was opened');
            $table->string('status', 20)->default('open')->comment('open · decided · appealed · resolved');
            $table->timestamp('respond_by')->nullable();
            $table->string('decision', 10)->nullable()->comment('refund · release · split');
            $table->unsignedSmallInteger('freelancer_bp')->nullable()->comment('the freelancer’s share of a split, basis points');
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('decided_by_admin_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executes_at')->nullable();
            $table->string('appealed_by', 12)->nullable();
            $table->text('appeal_reason')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->unsignedBigInteger('final_admin_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'executes_at']);
            $table->index(['milestone_id', 'status']);
        });

        Schema::create('freelance_dispute_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('freelance_disputes')->cascadeOnDelete();
            $table->string('side', 12)->comment('client · freelancer');
            $table->unsignedBigInteger('author_id');
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->index(['dispute_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_dispute_evidence');
        Schema::dropIfExists('freelance_disputes');
    }
};
