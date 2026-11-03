<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles in educational and work groups (docs/groups_tasks.md GR-004): each group has its roles —
 * ready templates (teacher, student… / manager, team member…) and the ones its managers make —
 * each a set of permissions. A member holds one role or more, each for the whole group or for one
 * space (term, subgroup, project — GR-007). No permission is ever given to one person directly.
 *
 * And the record of sensitive actions in those groups (GR-010): who did what, when, before/after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_group_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('template', 20)->nullable()->comment('teacher | assistant | grader | moderator | student | parent | manager | supervisor | member — null: made by the group');
            $table->string('name', 60)->nullable()->comment('null: the template\'s own name, in the reader\'s language');
            $table->json('permissions')->comment('GroupPermissions keys');
            $table->boolean('is_default')->default(false)->comment('Given to everyone who joins');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('conversation_id');
        });

        Schema::create('chat_group_member_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('chat_group_roles')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->unsignedBigInteger('space_id')->nullable()->comment('null: the whole group; else one term / subgroup / project (GR-007)');
            $table->timestamps();

            $table->unique(['role_id', 'participant_id', 'space_id'], 'chat_group_member_roles_unique');
            $table->index('participant_id');
        });

        Schema::create('chat_group_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('actor_type', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 40)->comment('role_created | role_updated | role_deleted | member_roles_changed | …');
            $table->string('subject', 80)->nullable()->comment('What it was done to: role:3, participant:12…');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_audit_log');
        Schema::dropIfExists('chat_group_member_roles');
        Schema::dropIfExists('chat_group_roles');
    }
};
