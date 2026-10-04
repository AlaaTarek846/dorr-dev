<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Privacy without circles (spec 104–113):
     *  - a sensitive message: its content never shows in a notification, and the app hides it until
     *    the recipient unlocks it,
     *  - a quick privacy mode (until a time) and a daily privacy schedule: while on, every chat
     *    notification says only "New message" (grouped into one on the phone),
     *  - when it started, for the "while you were private" summary.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('is_sensitive')->default(false)->after('is_urgent');
        });

        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->timestamp('privacy_mode_until')->nullable()->after('status_audience');
            $table->timestamp('privacy_mode_started_at')->nullable()->after('privacy_mode_until');
            $table->json('privacy_schedule')->nullable()->after('privacy_mode_started_at')->comment('{from: "22:00", to: "07:00", days: [0..6], timezone}');
        });
    }

    public function down(): void
    {
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->dropColumn(['privacy_mode_until', 'privacy_mode_started_at', 'privacy_schedule']);
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('is_sensitive');
        });
    }
};
