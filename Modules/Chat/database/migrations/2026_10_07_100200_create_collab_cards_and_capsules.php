<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR Moments, together and kept (spec 163, 166):
 *  - chat_collab_cards — one card many people sign (text, voice, photo) before the organiser sends
 *    it to the recipient, who knows nothing until it arrives.
 *  - chat_moment_capsules — an album of things I chose to keep for an occasion; each item is a
 *    copy (its text and files), so it outlives the chat's own copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_collab_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('recipient_type', 32);
            $table->unsignedBigInteger('recipient_id');
            $table->foreignId('chat_moment_id')->nullable()->constrained('chat_moments')->nullOnDelete();
            $table->string('personal_kind', 20)->nullable();
            $table->string('title', 120);
            $table->timestamp('deadline_at')->nullable();
            $table->string('status', 12)->default('collecting')->comment('collecting | sent | cancelled');
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete()->comment('the card it became');
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'status']);
        });

        Schema::create('chat_collab_card_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collab_card_id')->constrained('chat_collab_cards')->cascadeOnDelete();
            $table->string('member_type', 32);
            $table->unsignedBigInteger('member_id');
            $table->text('text')->nullable()->comment('their part, once they add it');
            $table->timestamp('contributed_at')->nullable();
            $table->timestamps();
            $table->unique(['collab_card_id', 'member_type', 'member_id'], 'chat_collab_members_unique');
            $table->index(['member_type', 'member_id']);
        });

        Schema::create('chat_moment_capsules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('title', 120);
            $table->string('emoji', 16)->nullable();
            $table->foreignId('chat_moment_id')->nullable()->constrained('chat_moments')->nullOnDelete();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('chat_moment_capsule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capsule_id')->constrained('chat_moment_capsules')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('text')->nullable();
            $table->string('sender_name', 120)->nullable();
            $table->string('note', 300)->nullable();
            $table->uuid('source_message_id')->nullable()->comment('where it came from (it may be gone)');
            $table->timestamp('original_at')->nullable();
            $table->timestamps();
            $table->unique(['capsule_id', 'source_message_id'], 'chat_capsule_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_moment_capsule_items');
        Schema::dropIfExists('chat_moment_capsules');
        Schema::dropIfExists('chat_collab_card_members');
        Schema::dropIfExists('chat_collab_cards');
    }
};
