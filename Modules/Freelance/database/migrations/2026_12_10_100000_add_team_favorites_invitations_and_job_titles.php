<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-213: a client keeps the freelancers' teams he worked with (next to the freelancers he keeps, FL-306) and invites a team to a private
 * project (FL-205): the invitation goes to the team's Owner, who answers and offers in the team's name. A member has a job title (what he does
 * in the team, written by an Owner or by himself), shown on the team's profile next to his name when he agreed to be shown (D10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_client_team_favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('freelance_teams')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'team_id']);
            $table->index('team_id');
        });

        Schema::table('freelance_project_invitations', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('freelancer_id')->comment('FS-213: invited as this team (the row is its Owner\'s)')
                ->constrained('freelance_teams', 'id', 'fl_invitation_team_fk')->nullOnDelete();
        });

        Schema::table('freelance_team_members', function (Blueprint $table) {
            $table->string('job_title', 60)->nullable()->after('role')->comment('FS-213: what the person does in the team');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_team_members', function (Blueprint $table) {
            $table->dropColumn('job_title');
        });
        Schema::table('freelance_project_invitations', function (Blueprint $table) {
            $table->dropForeign('fl_invitation_team_fk');
            $table->dropColumn('team_id');
        });
        Schema::dropIfExists('freelance_client_team_favorites');
    }
};
