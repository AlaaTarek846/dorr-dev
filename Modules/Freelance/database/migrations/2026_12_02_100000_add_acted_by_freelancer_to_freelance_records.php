<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-206a: somebody of a freelancers' team works on a team's project for the Owner who signed it - the request runs as that Owner (his
 * agreement, his wallet, his checks) and every record row keeps who really did it, like `acted_by_user_id` for the client's team (FS-104).
 * No foreign key, like its twin: the rows are history and stay when an account goes.
 */
return new class extends Migration
{
    private const TABLES = ['freelance_project_approvals', 'freelance_project_events', 'freelance_milestone_events', 'freelance_offer_events'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('acted_by_freelancer_id')->nullable()->after('acted_by_user_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('acted_by_freelancer_id');
            });
        }
    }
};
