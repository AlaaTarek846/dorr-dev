<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-150c: who a project event is for. `both` (every row so far) is shown to the client and the freelancer; `client` / `freelancer` only to
 * that side; `team` only to the freelancer's team (it arrives with the teams, FS-207) - the client never sees it. Staff read every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_project_events', function (Blueprint $table) {
            $table->string('audience', 12)->default('both')->after('payload')->comment('both | client | freelancer | team');
            $table->index(['project_id', 'audience', 'id'], 'fpe_project_audience_id');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_project_events', function (Blueprint $table) {
            $table->dropIndex('fpe_project_audience_id');
            $table->dropColumn('audience');
        });
    }
};
