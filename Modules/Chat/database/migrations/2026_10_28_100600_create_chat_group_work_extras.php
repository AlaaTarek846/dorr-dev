<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work group extras and the admin side (docs/groups_tasks.md):
 * - GR-040 shifts, their hand-over note and swaps between members.
 * - GR-042 team competitions: a goal, points given to members, a leaderboard.
 * - GR-043 badges given to members.
 * - GR-044 the ideas bank: ideas, votes, a status.
 * - GR-046 meetings: agenda and minutes (tasks can be made from the minutes).
 * - GR-050 a group the admin turned off (read only, the owner can't undo it).
 * - GR-051 extra tools the admin switched off for every group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_group_shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete()->comment('Whose shift');
            $table->string('title', 150)->nullable()->comment('Morning, the till, the warehouse…');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->text('handover_note')->nullable()->comment('For whoever comes next');
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('swap_with_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete()->comment('A swap asked for, waiting for that member');
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'starts_at']);
        });

        Schema::create('chat_group_goals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chat_group_goal_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained('chat_group_goals')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->integer('points');
            $table->string('note', 300)->nullable();
            $table->foreignId('given_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();

            $table->index(['goal_id', 'participant_id']);
        });

        Schema::create('chat_group_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('emoji', 16)->default('🏅');
            $table->string('title', 100);
            $table->string('reason', 300)->nullable();
            $table->foreignId('given_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chat_group_ideas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('status', 14)->default('new')->comment('new | under_review | accepted | rejected | done');
            $table->string('status_note', 500)->nullable();
            $table->unsignedInteger('votes')->default(0);
            $table->timestamps();
        });

        Schema::create('chat_group_idea_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('idea_id')->constrained('chat_group_ideas')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['idea_id', 'participant_id']);
        });

        Schema::create('chat_group_meetings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('chat_group_spaces')->cascadeOnDelete();
            $table->string('title', 200);
            $table->timestamp('starts_at');
            $table->text('agenda')->nullable();
            $table->text('minutes')->nullable();
            $table->foreignId('minutes_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'starts_at']);
        });

        Schema::table('chat_groups', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('archived_at')->comment('Turned off by the admin: read only, the owner cannot undo it (GR-050)');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->json('group_tools_disabled')->nullable()->after('group_restore_days')->comment('Extra tools of educational / work groups switched off (GR-051)');
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn('group_tools_disabled');
        });
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
        Schema::dropIfExists('chat_group_meetings');
        Schema::dropIfExists('chat_group_idea_votes');
        Schema::dropIfExists('chat_group_ideas');
        Schema::dropIfExists('chat_group_badges');
        Schema::dropIfExists('chat_group_goal_points');
        Schema::dropIfExists('chat_group_goals');
        Schema::dropIfExists('chat_group_shifts');
    }
};
