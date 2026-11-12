<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — quality and trust (docs/freelance_tasks.md, owner's decisions 2026-11-12).
 *
 *  - FS-601 Verified Portfolio: a work made from a project finished on Dorr whose client allows it in the portfolio (FL-L13) is «Verified by
 *    Dorr». The work now tells its story (problem, solution, role, tools, results); staff can take the badge back with a reason.
 *  - FS-602 Skill Certification v2: a badge has a level. Beginner and intermediate come from the test score (the second from the test's
 *    `intermediate_percent`); advanced and expert need a practical challenge staff review. Badges issued before keep working (their level comes
 *    from their score).
 *  - FS-604 Reputation: a freelancer asks staff to correct the data of one project behind a delivery or completion figure; an accepted request
 *    takes that project out of the figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelancer_portfolio_items', function (Blueprint $table) {
            $table->text('case_problem')->nullable()->after('description')->comment('FS-601: the case study');
            $table->text('case_solution')->nullable()->after('case_problem');
            $table->string('case_role', 300)->nullable()->after('case_solution');
            $table->string('case_tools', 300)->nullable()->after('case_role');
            $table->text('case_results')->nullable()->after('case_tools');
            $table->timestamp('verified_revoked_at')->nullable()->after('hidden_by_admin_id')->comment('FS-601: staff took the «verified» badge back');
            $table->string('verified_revoked_reason', 255)->nullable()->after('verified_revoked_at');
            $table->foreignId('verified_revoked_by_admin_id')->nullable()->after('verified_revoked_reason')->constrained('admins', 'id', 'fl_portfolio_unverify_fk')->nullOnDelete();
        });

        Schema::table('freelance_skill_tests', function (Blueprint $table) {
            $table->unsignedTinyInteger('intermediate_percent')->default(85)->after('pass_percent')->comment('FS-602: this score or more = intermediate');
            $table->boolean('challenge_enabled')->default(false)->after('retake_hours')->comment('FS-602: advanced / expert by a practical challenge');
            $table->json('challenge_brief')->nullable()->after('challenge_enabled')->comment('{ar, en} what the freelancer builds and sends');
        });

        Schema::table('freelancer_skill_badges', function (Blueprint $table) {
            $table->string('level', 12)->default('beginner')->after('score_percent')->comment('FS-602: beginner | intermediate | advanced | expert');
        });
        // The badges already out keep working: their level is their score's (85 % or more was the top score band before).
        DB::table('freelancer_skill_badges')->where('score_percent', '>=', 85)->update(['level' => 'intermediate']);

        Schema::create('freelance_skill_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('freelance_skill_id')->constrained('freelance_skills')->cascadeOnDelete();
            $table->string('target_level', 12)->comment('advanced | expert');
            $table->string('link', 255)->nullable();
            $table->text('note');
            $table->string('status', 12)->default('submitted')->comment('submitted | accepted | rejected | withdrawn');
            $table->string('granted_level', 12)->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins', 'id', 'fl_challenge_admin_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['freelancer_id', 'freelance_skill_id', 'status'], 'fl_challenge_lookup');
            $table->index(['status', 'created_at']);
        });

        Schema::create('freelance_reputation_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->string('indicator', 20)->comment('delivery | completion');
            $table->string('reason', 30);
            $table->string('note', 1000);
            $table->string('status', 12)->default('open')->comment('open | accepted | rejected');
            $table->string('decision_note', 1000)->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins', 'id', 'fl_reputation_admin_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['freelancer_id', 'indicator', 'status'], 'fl_reputation_lookup');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_reputation_corrections');
        Schema::dropIfExists('freelance_skill_challenges');
        Schema::table('freelancer_skill_badges', fn (Blueprint $t) => $t->dropColumn('level'));
        Schema::table('freelance_skill_tests', fn (Blueprint $t) => $t->dropColumn(['intermediate_percent', 'challenge_enabled', 'challenge_brief']));
        Schema::table('freelancer_portfolio_items', function (Blueprint $table) {
            $table->dropForeign('fl_portfolio_unverify_fk');
            $table->dropColumn(['case_problem', 'case_solution', 'case_role', 'case_tools', 'case_results', 'verified_revoked_at', 'verified_revoked_reason', 'verified_revoked_by_admin_id']);
        });
    }
};
