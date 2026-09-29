<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link previews, group join approval, polls, view-once media and live location
     * (docs/chat-tasks.md). Everything else these need lives in `chat_messages.meta`.
     */
    public function up(): void
    {
        // One fetch per URL, shared by every message that links to it (and re-fetched after a day).
        Schema::create('chat_link_previews', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64)->unique()->comment('sha256 of the normalised URL');
            $table->text('url');
            $table->string('title', 300)->nullable();
            $table->string('description', 500)->nullable();
            $table->text('image')->nullable();
            $table->string('site_name', 150)->nullable();
            $table->boolean('failed')->default(false)->comment('nothing usable — no card');
            $table->timestamp('fetched_at');
            $table->timestamps();
        });

        Schema::table('chat_groups', function (Blueprint $table) {
            $table->boolean('approve_joins')->default(false)->after('only_admins_add_members')->comment('invite-link joins wait for an admin');
        });

        Schema::create('chat_group_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('requester_type', 32);
            $table->unsignedBigInteger('requester_id');
            $table->string('status', 16)->default('pending')->comment('pending | approved | rejected | cancelled');
            $table->foreignId('decided_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->index(['requester_type', 'requester_id']);
        });

        Schema::create('chat_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->unsignedTinyInteger('option_id');
            $table->timestamps();

            $table->unique(['message_id', 'participant_id', 'option_id']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('view_once')->default(false)->after('has_link');
        });

        Schema::table('chat_message_user_states', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('deleted_at')->comment('view-once media opened');
        });
    }

    public function down(): void
    {
        Schema::table('chat_message_user_states', fn (Blueprint $table) => $table->dropColumn('opened_at'));
        Schema::table('chat_messages', fn (Blueprint $table) => $table->dropColumn('view_once'));
        Schema::dropIfExists('chat_poll_votes');
        Schema::dropIfExists('chat_group_join_requests');
        Schema::table('chat_groups', fn (Blueprint $table) => $table->dropColumn('approve_joins'));
        Schema::dropIfExists('chat_link_previews');
    }
};
