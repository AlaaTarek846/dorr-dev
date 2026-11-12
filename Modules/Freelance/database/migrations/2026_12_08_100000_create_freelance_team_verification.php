<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-202b: verifying a freelancers' team by hand (D4, optional). The Owner uploads papers (a commercial register, a partnership agreement...:
 * a private file with a title) and sends them as one request; an admin approves it (the team gets `verified_at` and a badge) or rejects it
 * with a reason. A paper not sent yet has no request (`request_id` null). Every request is kept, so a second try never erases the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_team_verification_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('freelance_teams', 'id', 'fl_tvreq_team_fk')->cascadeOnDelete();
            $table->foreignId('submitted_by_freelancer_id')->nullable()->constrained('freelancers', 'id', 'fl_tvreq_by_fk')->nullOnDelete();
            $table->string('status', 12)->default('submitted')->comment('submitted / approved / rejected');
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins', 'id', 'fl_tvreq_admin_fk')->nullOnDelete();
            $table->string('reason', 500)->nullable()->comment('why it was rejected; shown to the Owners');
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
            $table->index(['team_id', 'id']);
        });

        Schema::create('freelance_team_verification_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('freelance_teams', 'id', 'fl_tvdoc_team_fk')->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('freelance_team_verification_requests', 'id', 'fl_tvdoc_req_fk')->nullOnDelete()->comment('null = not sent yet');
            $table->foreignId('uploaded_by_freelancer_id')->nullable()->constrained('freelancers', 'id', 'fl_tvdoc_by_fk')->nullOnDelete();
            $table->string('title', 120);
            $table->timestamps();
            $table->index(['team_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_team_verification_documents');
        Schema::dropIfExists('freelance_team_verification_requests');
    }
};
