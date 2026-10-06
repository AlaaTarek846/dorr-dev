<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Public stories on the home page (docs/remaining_chat.md ج.1):
 *
 *  - `chat_stories.is_public` — seen by everyone on the home page (still also by my usual audience
 *    in the chat). Each person may have `chat_settings.public_stories_free` of them running.
 *  - `chat_dorr_stories` — Dorr's own stories (ads), made in the admin: the fixed third circle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_stories', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('allow_replies')->comment('on the home page for everyone');
            $table->index(['is_public', 'expires_at'], 'chat_stories_public_index');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->boolean('public_stories_enabled')->default(true)->after('stories_enabled');
            $table->unsignedTinyInteger('public_stories_free')->default(1)->after('public_stories_enabled')->comment('public stories one person may have running for free');
        });

        Schema::create('chat_dorr_stories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 8)->comment('text | image | video');
            $table->text('body')->nullable()->comment('the text, or a caption');
            $table->json('style')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('link_url', 500)->nullable()->comment('"Open" button on the story');
            $table->string('link_label', 60)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at']);
        });

        Schema::create('chat_dorr_story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dorr_story_id')->constrained('chat_dorr_stories')->cascadeOnDelete();
            $table->string('viewer_type', 32);
            $table->unsignedBigInteger('viewer_id');
            $table->timestamps();
            $table->unique(['dorr_story_id', 'viewer_type', 'viewer_id'], 'chat_dorr_story_views_unique');
        });

        Cache::forget('chat.settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_dorr_story_views');
        Schema::dropIfExists('chat_dorr_stories');
        Schema::table('chat_settings', fn (Blueprint $table) => $table->dropColumn(['public_stories_enabled', 'public_stories_free']));
        Schema::table('chat_stories', function (Blueprint $table) {
            $table->dropIndex('chat_stories_public_index');
            $table->dropColumn('is_public');
        });
        Cache::forget('chat.settings');
    }
};
