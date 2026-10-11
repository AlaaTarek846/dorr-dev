<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A call turned into a live broadcast (spec 93): the people in the call go on talking, viewers
 * watch and listen — they join the same LiveKit room with a token that can't publish and keeps
 * them hidden. Who may watch: the chat's members, or anyone in DORR with the link. It ends with
 * the call (or when its starter ends it). The card can be shared into any chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_call_lives', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->unique()->constrained('chat_calls')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('started_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->string('title', 120)->nullable();
            $table->string('audience', 10)->default('members')->comment('members · link');
            $table->string('token', 40)->unique()->comment('in the share link');
            $table->string('status', 10)->default('live')->comment('live · ended');
            $table->unsignedInteger('viewers_peak')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_call_live_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_id')->constrained('chat_call_lives')->cascadeOnDelete();
            $table->string('viewer_type', 40);
            $table->unsignedBigInteger('viewer_id');
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->unique(['live_id', 'viewer_type', 'viewer_id'], 'chat_live_viewer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_call_live_viewers');
        Schema::dropIfExists('chat_call_lives');
    }
};
