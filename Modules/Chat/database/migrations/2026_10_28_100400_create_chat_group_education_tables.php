<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Educational groups (docs/groups_tasks.md GR-013..017). Each item lives in the group and may be
 * shown to the whole group (`space_id` null) or to one term / subgroup only. Files are kept on the
 * private disk and read through signed links (GroupMedia).
 *
 * - Lessons and materials (GR-013).
 * - Homework: individual or team hand-ins, the last version counts, a grade for each student (GR-014).
 * - Tests: choice / true-false marked automatically, written answers by hand; a student sees only
 *   their own result (GR-015). The teacher's question bank (GR-016).
 * - Class sessions and attendance — every change on the activity log (GR-017).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_group_lessons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('chat_group_spaces')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'space_id']);
        });

        Schema::create('chat_group_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('chat_group_spaces')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('instructions')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('mode', 12)->default('individual')->comment('individual | team');
            $table->unsignedSmallInteger('max_score')->nullable();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'space_id']);
        });

        Schema::create('chat_group_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('chat_group_assignments')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete()->comment('Who handed it in');
            $table->json('team')->nullable()->comment('Team mode: participant ids handing in together (the submitter included)');
            $table->text('body')->nullable();
            $table->unsignedSmallInteger('version')->default(1)->comment('Handing in again replaces it; the last one counts');
            $table->boolean('is_late')->default(false);
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['assignment_id', 'participant_id']);
        });

        Schema::create('chat_group_grades', function (Blueprint $table) {
            $table->id();
            $table->string('gradable_type', 20)->comment('assignment | quiz');
            $table->unsignedBigInteger('gradable_id');
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete()->comment('The student — one grade each, even in a team');
            $table->decimal('score', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['gradable_type', 'gradable_id', 'participant_id'], 'chat_group_grades_unique');
        });

        Schema::create('chat_group_quizzes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('chat_group_spaces')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('instructions')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('results_visible')->default(true)->comment('Students see their own result once marked');
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'space_id']);
        });

        Schema::create('chat_group_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('chat_group_quizzes')->cascadeOnDelete();
            $table->string('type', 12)->comment('choice | true_false | essay');
            $table->text('text');
            $table->json('options')->nullable()->comment('choice: the answers to pick from');
            $table->json('correct')->nullable()->comment('choice: index list · true_false: bool · essay: null (marked by hand)');
            $table->decimal('points', 5, 2)->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('chat_group_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('chat_group_quizzes')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->json('answers')->nullable()->comment('question id => the answer');
            $table->json('marks')->nullable()->comment('question id => points given');
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('needs_marking')->default(false)->comment('Has written answers not marked yet');
            $table->timestamps();

            $table->unique(['quiz_id', 'participant_id']);
        });

        Schema::create('chat_group_question_bank', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->string('subject', 100)->nullable();
            $table->string('unit', 100)->nullable();
            $table->string('difficulty', 8)->nullable()->comment('easy | medium | hard');
            $table->string('type', 12);
            $table->text('text');
            $table->json('options')->nullable();
            $table->json('correct')->nullable();
            $table->decimal('points', 5, 2)->default(1);
            $table->timestamps();

            $table->index(['conversation_id', 'subject', 'unit']);
        });

        Schema::create('chat_group_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('chat_group_spaces')->cascadeOnDelete();
            $table->string('title', 200);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'starts_at']);
        });

        Schema::create('chat_group_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('chat_group_sessions')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('status', 10)->comment('present | absent | late | excused');
            $table->string('note', 300)->nullable();
            $table->foreignId('recorded_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();

            $table->unique(['session_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_attendance');
        Schema::dropIfExists('chat_group_sessions');
        Schema::dropIfExists('chat_group_question_bank');
        Schema::dropIfExists('chat_group_quiz_attempts');
        Schema::dropIfExists('chat_group_quiz_questions');
        Schema::dropIfExists('chat_group_quizzes');
        Schema::dropIfExists('chat_group_grades');
        Schema::dropIfExists('chat_group_submissions');
        Schema::dropIfExists('chat_group_assignments');
        Schema::dropIfExists('chat_group_lessons');
    }
};
