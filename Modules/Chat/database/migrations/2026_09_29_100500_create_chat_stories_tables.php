<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stories / Status (docs/chat-plan.md §10.1). A story is shown to the audience it had *when it
 * was posted* (chat_story_recipients is that snapshot), so changing your story privacy later
 * never exposes older stories to new people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_stories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('type', 8)->comment('text | image | video');
            $table->text('body')->nullable()->comment('the text of a text story, or a caption');
            $table->json('style')->nullable()->comment('text stories: background gradient, font, alignment');
            $table->unsignedInteger('duration_ms')->nullable()->comment('video length');
            $table->boolean('allow_replies')->default(true);
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'expires_at'], 'chat_stories_owner_index');
        });

        Schema::create('chat_story_recipients', function (Blueprint $table) {
            $table->foreignId('story_id')->constrained('chat_stories')->cascadeOnDelete();
            $table->string('participant_type', 32);
            $table->unsignedBigInteger('participant_id');

            $table->primary(['story_id', 'participant_type', 'participant_id'], 'chat_story_recipients_pk');
            $table->index(['participant_type', 'participant_id'], 'chat_story_recipients_viewer_index');
        });

        Schema::create('chat_story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('chat_stories')->cascadeOnDelete();
            $table->string('viewer_type', 32);
            $table->unsignedBigInteger('viewer_id');
            $table->timestamp('viewed_at');
            $table->string('reaction', 32)->nullable();
            // Viewer has read receipts off: they saw it, but the owner isn't told (like WhatsApp).
            $table->boolean('hidden')->default(false);

            $table->unique(['story_id', 'viewer_type', 'viewer_id'], 'chat_story_views_unique');
        });

        Schema::create('chat_story_mutes', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32)->comment('who muted');
            $table->unsignedBigInteger('owner_id');
            $table->string('muted_type', 32);
            $table->unsignedBigInteger('muted_id');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'muted_type', 'muted_id'], 'chat_story_mutes_unique');
        });

        // "My contacts except…" and "Only share with…" lists.
        Schema::create('chat_story_privacy_members', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('list', 8)->comment('except | only');
            $table->string('member_type', 32);
            $table->unsignedBigInteger('member_id');

            $table->unique(['owner_type', 'owner_id', 'list', 'member_type', 'member_id'], 'chat_story_privacy_unique');
        });

        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->string('story_audience', 16)->default('contacts')->after('who_can_call')->comment('contacts | except | only');
        });
    }

    public function down(): void
    {
        Schema::table('chat_privacy_settings', fn (Blueprint $table) => $table->dropColumn('story_audience'));
        Schema::dropIfExists('chat_story_privacy_members');
        Schema::dropIfExists('chat_story_mutes');
        Schema::dropIfExists('chat_story_views');
        Schema::dropIfExists('chat_story_recipients');
        Schema::dropIfExists('chat_stories');
    }
};
