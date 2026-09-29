<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A conversation and who is in it (docs/chat-plan.md §10). Direct chats and groups share
 * the same shape: a direct chat simply has exactly two participants. Everything a person
 * decides about a conversation *for themselves* (pin, archive, mute, lock, clear, unread…)
 * lives on their own chat_participants row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 16)->comment('direct | group');
            $table->string('status', 16)->default('accepted')->comment('pending | accepted | rejected — message requests (direct only)');
            // "user:1|user:7" (sorted) — makes a second direct chat between the same two people impossible.
            $table->string('direct_key', 80)->nullable()->unique();
            $table->string('created_by_type', 32)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->unsignedInteger('disappearing_seconds')->nullable()->comment('الرسائل المختفية — للمحادثة كلها');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('chat_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('invite_token', 64)->nullable()->unique();
            $table->boolean('only_admins_send')->default(false);
            $table->boolean('only_admins_edit_info')->default(true);
            $table->boolean('only_admins_add_members')->default(false);
            $table->timestamps();
        });

        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('participant_type', 32);
            $table->unsignedBigInteger('participant_id');
            $table->string('role', 16)->default('member')->comment('member | admin | owner');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable()->comment('left or removed — the row stays so history keeps its sender');
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->unsignedBigInteger('last_delivered_message_id')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('marked_unread')->default(false);
            $table->boolean('has_unread_mention')->default(false);
            $table->timestamp('pinned_at')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestamp('muted_until')->nullable();
            $table->unsignedBigInteger('cleared_before_message_id')->nullable()->comment('messages up to this id are hidden for this person');
            $table->boolean('is_deleted')->default(false)->comment('chat deleted for me — comes back on the next message');
            $table->unsignedBigInteger('theme_id')->nullable();
            $table->json('custom_theme')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'participant_type', 'participant_id'], 'chat_participants_unique');
            $table->index(['participant_type', 'participant_id'], 'chat_participants_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_groups');
        Schema::dropIfExists('chat_conversations');
    }
};
