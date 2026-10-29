<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group's kind (docs/groups_tasks.md GR-001): normal, education or work — picked when the group
 * is made and never changed. Every group made before this is a normal one. The chat settings get
 * a switch per kind and how many of them one person may own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->string('kind', 12)->default('normal')->after('conversation_id')->index()
                ->comment('normal | education | work — fixed once the group exists');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->boolean('education_groups_enabled')->default(true)->after('max_group_members');
            $table->boolean('work_groups_enabled')->default(true)->after('education_groups_enabled');
            $table->unsignedSmallInteger('max_owned_kind_groups')->default(0)->after('work_groups_enabled')
                ->comment('Educational / work groups one person may own, per kind (0 = no limit)');
        });
    }

    public function down(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn(['education_groups_enabled', 'work_groups_enabled', 'max_owned_kind_groups']);
        });
    }
};
