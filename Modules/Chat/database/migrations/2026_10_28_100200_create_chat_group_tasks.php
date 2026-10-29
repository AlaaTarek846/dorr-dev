<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks in work groups (docs/groups_tasks.md GR-033..036): in the group or inside one of its
 * projects, given to a member with a due time and a priority; repeating (GR-034); a checklist and
 * proof of the work (GR-035); and a manager's approval when the task asks for it (GR-036).
 *
 * Status: new → in_progress → in_review (when it needs approval) → done; or cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_group_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('chat_group_spaces')->cascadeOnDelete()->comment('Inside a project (GR-037); null: the whole group');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('assignee_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->foreignId('created_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->string('priority', 8)->default('normal')->comment('low | normal | high | urgent');
            $table->string('status', 12)->default('new')->comment('new | in_progress | in_review | done | cancelled');
            $table->timestamp('due_at')->nullable();
            $table->boolean('needs_approval')->default(false);
            $table->boolean('requires_proof')->default(false);
            $table->text('proof_note')->nullable();
            $table->text('review_note')->nullable()->comment('Why the manager sent it back');
            $table->foreignId('reviewed_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('repeat', 8)->nullable()->comment('daily | weekly | monthly (GR-034)');
            $table->date('repeat_until')->nullable();
            $table->unsignedBigInteger('series_id')->nullable()->index()->comment('The first task of a repeating series');
            $table->timestamp('next_spawned_at')->nullable()->comment('The next one in the series was made');
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->index('assignee_participant_id');
        });

        Schema::create('chat_group_task_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('chat_group_tasks')->cascadeOnDelete();
            $table->string('text', 300);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_task_items');
        Schema::dropIfExists('chat_group_tasks');
    }
};
