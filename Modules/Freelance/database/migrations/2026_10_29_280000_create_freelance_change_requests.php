<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-404 / FL-405: a change to an active agreement asked by one side and answered by the other — more days, the scope's text, a new
 * price for a part not funded yet, or a new part for more work. Never deleted; what it changed is kept with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('freelance_milestones')->cascadeOnDelete();
            $table->string('type', 20)->comment('extend_days · scope · milestone_price · add_milestone');
            $table->string('requested_by', 12)->comment('client · freelancer');
            $table->unsignedBigInteger('requester_id');
            $table->json('payload')->comment('what changes: the new values, and the old ones');
            $table->string('reason', 1000)->nullable();
            $table->string('status', 12)->default('pending')->comment('pending · accepted · rejected · withdrawn · cancelled');
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->index(['agreement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_change_requests');
    }
};
