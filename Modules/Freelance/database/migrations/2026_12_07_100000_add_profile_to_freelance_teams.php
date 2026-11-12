<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-202a: a team's profile. The team tells about itself (bio, logo as media), says whether it is open to the public or only to the clients it
 * made an offer to, and whether it takes new projects now. `verified_at` is the optional team verification (D4) - its review is FS-202b.
 * A member shows on the profile only with his own consent (D10: `show_in_profile`, off by default). The team's works are portfolio items with
 * a `team_id` (the person who added them stays as `freelancer_id`); a personal portfolio never lists them.
 *
 * Specialties, skills and languages are not stored: they are the union of the active members' (D10), worked out when the profile is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_teams', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('name');
            $table->string('visibility', 10)->default('private')->after('bio')->comment('public | private');
            $table->boolean('accepting_projects')->default(true)->after('visibility');
            $table->timestamp('verified_at')->nullable()->after('accepting_projects')->comment('D4 team verification (FS-202b)');
        });

        Schema::table('freelance_team_members', function (Blueprint $table) {
            $table->boolean('show_in_profile')->default(false)->after('permissions')->comment('D10: the member agreed to be shown on the team profile');
        });

        Schema::table('freelancer_portfolio_items', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('freelancer_id')->constrained('freelance_teams', 'id', 'fl_portfolio_team_fk')->cascadeOnDelete();
            $table->index(['team_id', 'sort_order'], 'fl_portfolio_team_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('freelancer_portfolio_items', function (Blueprint $table) {
            $table->dropForeign('fl_portfolio_team_fk');
            $table->dropIndex('fl_portfolio_team_order_idx');
            $table->dropColumn('team_id');
        });
        Schema::table('freelance_team_members', function (Blueprint $table) {
            $table->dropColumn('show_in_profile');
        });
        Schema::table('freelance_teams', function (Blueprint $table) {
            $table->dropColumn(['bio', 'visibility', 'accepting_projects', 'verified_at']);
        });
    }
};
