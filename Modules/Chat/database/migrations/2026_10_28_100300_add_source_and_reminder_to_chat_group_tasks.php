<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work-group tasks (docs/groups_tasks.md):
 * - GR-038 — the message a task was made from: a safe reference, never a copy. If the message is
 *   deleted or its reader loses access, the task stays and the message's words don't show.
 * - GR-039 — when the assignee was reminded that it's due soon (once per due time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_group_tasks', function (Blueprint $table) {
            $table->foreignId('source_message_id')->nullable()->after('space_id')->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('reminded_at')->nullable()->after('next_spawned_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_group_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_message_id');
            $table->dropColumn('reminded_at');
        });
    }
};
