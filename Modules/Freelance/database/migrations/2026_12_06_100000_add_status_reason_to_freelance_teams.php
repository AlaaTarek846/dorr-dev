<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-208: staff suspend a team with a reason (and bring it back); the reason and the day are kept on the team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_teams', function (Blueprint $table) {
            $table->string('status_reason', 255)->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_teams', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'status_changed_at']);
        });
    }
};
