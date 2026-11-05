<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How people join a group (docs/groups_tasks.md GR-003): `direct`, `approval` (an admin lets them
 * in), or `request` (like approval, and the person may say why). `approve_joins` stays in step
 * (on for approval and request) so older apps keep working. A request keeps its reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->string('join_mode', 10)->default('direct')->after('approve_joins')
                ->comment('direct | approval | request — approve_joins is on for the last two');
        });
        DB::table('chat_groups')->where('approve_joins', true)->update(['join_mode' => 'approval']);

        Schema::table('chat_group_join_requests', function (Blueprint $table) {
            $table->string('reason', 300)->nullable()->after('requester_id')->comment('Why they want to join (join_mode = request)');
        });
    }

    public function down(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropColumn('join_mode');
        });

        Schema::table('chat_group_join_requests', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
