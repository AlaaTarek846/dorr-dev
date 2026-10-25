<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-205: the client invites freelancers to a private project; each can say yes or no, and the client then chooses among the offers
 * like on any project. One row per project and freelancer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_project_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('sent')->comment('sent · accepted · declined · withdrawn · expired');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'freelancer_id']);
            $table->index(['freelancer_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_project_invitations');
    }
};
