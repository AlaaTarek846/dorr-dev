<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-150d: a freelancers' team talks inside, apart from the chat with the client (docs/modules/freelance/TEAMS-ERD.md §4). Its own tables,
 * because the client chat's room always has a client. A team has one general thread and one thread per project it works on; only the
 * team's active people read and write them. The staff read a project's threads only while that project has a dispute being decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_internal_threads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('freelance_teams')->cascadeOnDelete();
            // Null = the team's general thread; otherwise the project the thread is about.
            $table->foreignId('project_id')->nullable()->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('created_by_freelancer_id')->nullable()->constrained('freelancers')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 120)->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'project_id']);
            $table->index(['project_id']);
        });

        Schema::create('freelance_internal_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('freelance_internal_threads')->cascadeOnDelete();
            $table->foreignId('sender_freelancer_id')->nullable()->constrained('freelancers')->nullOnDelete();
            $table->string('kind', 12)->default('text')->comment('text (files come with the team space, FS-207)');
            // Kept after its sender deleted it only while the project has a dispute being decided (the staff may need it).
            $table->text('body')->nullable();
            // The app's own id for the message, so sending it twice on a weak network stores it once.
            $table->string('client_msg_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->unique(['thread_id', 'sender_freelancer_id', 'client_msg_id'], 'fim_thread_sender_client_unique');
            $table->index(['thread_id', 'id']);
        });

        Schema::create('freelance_internal_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('freelance_internal_threads')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['thread_id', 'freelancer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_internal_reads');
        Schema::dropIfExists('freelance_internal_messages');
        Schema::dropIfExists('freelance_internal_threads');
    }
};
