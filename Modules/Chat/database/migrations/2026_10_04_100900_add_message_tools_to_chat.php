<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Message tools (spec items 47, 65, 90, 116–118, 121):
     *  - urgent messages, which get through a mute when the recipient allows it (`who_can_urgent`),
     *  - "needs a reply": a message I keep on a follow-up list until I answer it,
     *  - a reminder on any message, at a time I pick,
     *  - a personal status (emoji, a few words, until when, who sees it).
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('is_urgent')->default(false)->after('is_silent');
        });

        Schema::table('chat_message_user_states', function (Blueprint $table) {
            $table->timestamp('follow_up_at')->nullable()->after('read_later_at');
        });

        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->string('who_can_urgent', 16)->default('contacts')->after('who_can_call')->comment('everyone | contacts | nobody');
            $table->string('status_emoji', 16)->nullable()->after('notification_privacy');
            $table->string('status_text', 100)->nullable()->after('status_emoji');
            $table->timestamp('status_until')->nullable()->after('status_text')->comment('null = until I clear it');
            $table->string('status_audience', 16)->default('contacts')->after('status_until')->comment('everyone | contacts | nobody');
        });

        Schema::create('chat_message_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->timestamp('remind_at');
            $table->string('note', 200)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'participant_id']);
            $table->index(['sent_at', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_reminders');
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->dropColumn(['who_can_urgent', 'status_emoji', 'status_text', 'status_until', 'status_audience']);
        });
        Schema::table('chat_message_user_states', function (Blueprint $table) {
            $table->dropColumn('follow_up_at');
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('is_urgent');
        });
    }
};
