<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * DORR Calendar & DORR Today (spec 201–207):
 *  - chat_calendar_items — my own appointments (by hand, or from a chat: "dates in this chat"). Kept
 *    as the real instant (UTC) plus the zone it was set in, so travelling never moves it (205,
 *    AT-CAL-01); all-day ones by their date. `dedupe_key` stops the same event twice (AT-CAL-02).
 *  - chat_calendar_preferences — which sources show (only what I chose, 201), default reminders and
 *    whether they wait for my quiet hours (204), the order of DORR Today's sections (202).
 *  - chat_settings.calendar_enabled — the admin's switch (the chat keeps working when off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_calendar_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('title', 160);
            $table->string('note', 1000)->nullable();
            $table->string('location', 200)->nullable();
            $table->timestamp('starts_at')->comment('UTC instant; all-day: the date at 00:00 in `timezone`');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->date('date')->nullable()->comment('all-day items: the day itself');
            $table->string('timezone', 64)->comment('the IANA zone it was set in');
            $table->string('color', 9)->nullable();
            $table->string('source', 20)->default('manual')->comment('manual | chat');
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('chat_conversations')->nullOnDelete();
            $table->string('dedupe_key', 80);
            $table->json('reminders')->nullable()->comment('minutes before (all-day: before 09:00 that day)');
            $table->json('reminded')->nullable()->comment('the offsets already sent for this start');
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'dedupe_key']);
            $table->index(['owner_type', 'owner_id', 'starts_at']);
            $table->index('starts_at');
        });

        Schema::create('chat_calendar_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->json('sources')->nullable()->comment('events, moments, personal, tasks, reminders → on/off');
            $table->json('default_reminders')->nullable();
            $table->json('all_day_reminders')->nullable();
            $table->boolean('respect_quiet')->default(true);
            $table->json('today_sections')->nullable()->comment('DORR Today: the order, and which are hidden');
            $table->boolean('personalised')->default(true)->comment('207: off = no "around my interests" summary');
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->boolean('calendar_enabled')->default(true)->after('moments_enabled');
        });
        Cache::forget('chat.settings');
    }

    public function down(): void
    {
        Schema::table('chat_settings', fn (Blueprint $table) => $table->dropColumn('calendar_enabled'));
        Schema::dropIfExists('chat_calendar_preferences');
        Schema::dropIfExists('chat_calendar_items');
        Cache::forget('chat.settings');
    }
};
