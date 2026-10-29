<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Educational and work groups (docs/groups_tasks.md):
 *
 * - GR-005 — handing the group over waits for the new owner's yes (`chat_group_ownership_transfers`).
 * - GR-006 — a group can be archived (read only), and a deleted one can be brought back for
 *   `group_restore_days` before it's gone for good (`purge_after`).
 * - GR-007 — terms, subgroups and projects: each its own group chat inside the main group, with
 *   its own members (`chat_group_spaces`). New members of the main group get none of them on
 *   their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_group_ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('from_participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->foreignId('to_participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('status', 12)->default('pending')->comment('pending | accepted | declined | cancelled');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });

        Schema::table('chat_groups', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('members_hidden_at')->comment('Read only while set (GR-006)');
            $table->timestamp('purge_after')->nullable()->after('archived_at')->comment('Deleted: can be brought back until then, then gone for good');
            $table->unsignedBigInteger('space_of_conversation_id')->nullable()->after('purge_after')->index()
                ->comment('A term / subgroup / project: the main group conversation (GR-007)');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('group_restore_days')->default(30)->after('max_owned_kind_groups')
                ->comment('Days a deleted educational / work group can be brought back');
        });

        Schema::create('chat_group_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('conversation_id')->unique()->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('type', 12)->comment('term | subgroup (education) · project (work)');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamp('closed_at')->nullable()->comment('Read only (a term that ended, a project that closed)');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('parent_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_spaces');
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn('group_restore_days');
        });
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropIndex(['space_of_conversation_id']);
            $table->dropColumn(['archived_at', 'purge_after', 'space_of_conversation_id']);
        });
        Schema::dropIfExists('chat_group_ownership_transfers');
    }
};
