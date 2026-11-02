<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skill tests and badges (FL-L08). A skill gets a test when the admin writes questions for it and switches the test on; a freelancer who
 * has the skill takes it, the server marks it, and a pass earns a verified badge for that skill. The right answers never leave the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Safe on MariaDB (whose old timestamp defaults reject a second NOT NULL timestamp with no default) and
        // safe to run again after a half-done run: a table that already exists is left as it is.
        // One row per skill that can be tested: how the test is set. Questions and options are translated maps ({ar, en}) in JSON.

        if (! Schema::hasTable('freelance_skill_tests')) {
            Schema::create('freelance_skill_tests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('freelance_skill_id')->unique()->constrained('freelance_skills')->cascadeOnDelete();
                $table->boolean('status')->default(false)->comment('on: freelancers can take it');
                $table->unsignedSmallInteger('questions_count')->default(10)->comment('how many of the bank each attempt draws');
                $table->unsignedSmallInteger('minutes')->default(15);
                $table->unsignedTinyInteger('pass_percent')->default(70);
                $table->unsignedSmallInteger('retake_hours')->default(24)->comment('wait after a failed attempt');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('freelance_skill_questions')) {
            Schema::create('freelance_skill_questions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('freelance_skill_id')->constrained('freelance_skills')->cascadeOnDelete();
                $table->json('prompt')->comment('{ar, en}');
                $table->json('options')->comment('[{text: {ar, en}, correct: bool}] — one correct');
                $table->boolean('status')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['freelance_skill_id', 'status']);
            });
        }

        // An attempt freezes the drawn questions (and their option order) so marking never depends on the bank changing meanwhile.

        if (! Schema::hasTable('freelance_skill_attempts')) {
            Schema::create('freelance_skill_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
                $table->foreignId('freelance_skill_id')->constrained('freelance_skills')->cascadeOnDelete();
                $table->json('questions')->comment('[{id, options: [original option index, in the shown order]}]');
                $table->string('status', 12)->default('started')->comment('started | passed | failed | expired');
                $table->unsignedTinyInteger('score_percent')->nullable();
                $table->unsignedSmallInteger('correct_count')->nullable();
                $table->unsignedSmallInteger('pass_percent')->comment('frozen from the test when it started');
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('expires_at')->useCurrent();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                $table->index(['freelancer_id', 'freelance_skill_id', 'status'], 'freelance_skill_attempts_lookup');
            });
        }

        if (! Schema::hasTable('freelancer_skill_badges')) {
            Schema::create('freelancer_skill_badges', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
                $table->foreignId('freelance_skill_id')->constrained('freelance_skills')->cascadeOnDelete();
                $table->foreignId('attempt_id')->nullable()->constrained('freelance_skill_attempts')->nullOnDelete();
                $table->unsignedTinyInteger('score_percent');
                $table->timestamp('earned_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_reason', 200)->nullable();
                $table->timestamps();

                $table->unique(['freelancer_id', 'freelance_skill_id']);
                $table->index(['freelance_skill_id', 'revoked_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('freelancer_skill_badges');
        Schema::dropIfExists('freelance_skill_attempts');
        Schema::dropIfExists('freelance_skill_questions');
        Schema::dropIfExists('freelance_skill_tests');
    }
};
