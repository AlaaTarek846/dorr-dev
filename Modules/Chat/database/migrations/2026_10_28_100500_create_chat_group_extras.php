<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Educational and work group extras (docs/groups_tasks.md):
 * - GR-022 test reminders and GR-024 class competitions (a test with a leaderboard).
 * - GR-025 stars of the week.
 * - GR-026 reading lists and each student's progress.
 * - GR-028 certificates (a PDF on the private disk).
 * - GR-031 / GR-041 help requests: to the teachers or the team, or to one person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_group_quizzes', function (Blueprint $table) {
            $table->boolean('is_competition')->default(false)->after('results_visible')->comment('A class competition: a leaderboard every student sees');
            $table->timestamp('reminded_at')->nullable()->after('is_competition');
        });

        Schema::create('chat_group_stars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete()->comment('The student');
            $table->date('week_of')->comment('The Monday of the week');
            $table->string('reason', 300)->nullable();
            $table->foreignId('awarded_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'participant_id', 'week_of']);
        });

        Schema::create('chat_group_reading_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('chat_group_spaces')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('author', 150)->nullable();
            $table->string('link', 500)->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chat_group_reading_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('chat_group_reading_items')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('status', 10)->default('reading')->comment('reading | done');
            $table->unsignedInteger('pages_read')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'participant_id']);
        });

        Schema::create('chat_group_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('reason', 500)->nullable();
            $table->foreignId('issued_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chat_group_help_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete()->comment('Who asks');
            $table->foreignId('to_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete()->comment('One person; null = the teachers / the team');
            $table->text('question');
            $table->string('status', 10)->default('open')->comment('open | answered | closed');
            $table->text('answer')->nullable();
            $table->foreignId('answered_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_help_requests');
        Schema::dropIfExists('chat_group_certificates');
        Schema::dropIfExists('chat_group_reading_progress');
        Schema::dropIfExists('chat_group_reading_items');
        Schema::dropIfExists('chat_group_stars');
        Schema::table('chat_group_quizzes', function (Blueprint $table) {
            $table->dropColumn(['is_competition', 'reminded_at']);
        });
    }
};
