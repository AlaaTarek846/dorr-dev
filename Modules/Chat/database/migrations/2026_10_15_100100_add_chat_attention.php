<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily intelligence, batch B (spec 146, 151, 155 — docs/remaining_chat.md):
 *  - attention — per source (contacts, strangers, groups, mentions, channels, money, urgent):
 *    now (interrupts) · digest (in the next summary) · later (no notification, waits in the
 *    life inbox), and how often the summary comes;
 *  - privacy contexts — home · work · meeting · travel · private, chosen now or for a while;
 *    each shifts the attention and what a notification shows, never what the server allows;
 *  - my communication rules — people who always get through, the hours calls may ring;
 *  - chat_attention_digests — what waits for the next summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->json('attention')->nullable()->comment('{source: now|digest|later}');
            $table->unsignedSmallInteger('digest_minutes')->default(60);
            $table->string('context', 12)->nullable()->comment('home · work · meeting · travel · private');
            $table->timestamp('context_until')->nullable();
            $table->json('contexts')->nullable()->comment('my own changes to each context: {key: {attention, disclosure, calls}}');
            $table->json('always_through')->nullable()->comment('participant keys who always get through');
            $table->json('call_hours')->nullable()->comment('{from, to, days[], timezone}: calls ring only inside');
        });

        Schema::create('chat_attention_digests', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 40);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedInteger('messages_count')->default(0);
            $table->json('conversation_ids')->nullable();
            $table->timestamp('first_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attention_digests');
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->dropColumn(['attention', 'digest_minutes', 'context', 'context_until', 'contexts', 'always_through', 'call_hours']);
        });
    }
};
