<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parents in educational groups (docs/groups_tasks.md GR-018, 019, 027 — decision D8: a parent is
 * an ordinary DORR account, never a member of the class chat, linked to one student with the
 * teacher's approval):
 * - the link: the student makes a code, the parent enters it, the teacher approves;
 * - whether parents may message the teachers privately (the teacher's switch);
 * - parents' meetings: one call for everyone, or slots each parent books (a call link of LiveKit).
 *
 * The `parent` role a new educational group used to start with goes: a parent is no member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_group_parent_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('student_participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('parent_type', 30)->nullable()->comment('Set when the parent enters the code');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 12)->nullable()->unique()->comment('The student gives it to the parent; gone once used');
            $table->timestamp('code_expires_at')->nullable();
            $table->string('status', 20)->default('invited')->comment('invited | pending | approved | declined');
            $table->foreignId('decided_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['parent_type', 'parent_id', 'status']);
            $table->index(['conversation_id', 'status']);
        });

        Schema::table('chat_groups', function (Blueprint $table) {
            $table->boolean('parents_can_message')->default(false)->after('disabled_at')->comment('GR-019: linked parents may message the teachers privately');
        });

        Schema::create('chat_group_parent_meetings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('note')->nullable();
            $table->timestamp('starts_at');
            $table->string('mode', 10)->default('group')->comment('group: one call for every parent | slots: each parent books a time');
            $table->unsignedSmallInteger('slot_minutes')->nullable();
            $table->unsignedSmallInteger('slots')->nullable();
            $table->foreignId('call_link_id')->nullable()->constrained('chat_call_links')->nullOnDelete();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'starts_at']);
        });

        Schema::create('chat_group_parent_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('chat_group_parent_meetings')->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('chat_group_parent_links')->cascadeOnDelete();
            $table->timestamp('slot_at');
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'link_id']);
            $table->unique(['meeting_id', 'slot_at']);
        });

        DB::table('chat_group_roles')->where('template', 'parent')->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_parent_bookings');
        Schema::dropIfExists('chat_group_parent_meetings');
        Schema::table('chat_groups', fn (Blueprint $table) => $table->dropColumn('parents_can_message'));
        Schema::dropIfExists('chat_group_parent_links');
    }
};
