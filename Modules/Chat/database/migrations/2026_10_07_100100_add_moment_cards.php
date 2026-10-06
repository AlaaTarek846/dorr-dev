<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Greeting cards (spec 161–167):
 *  - `users.timezone` — the phone's own zone (sent by the app), so a card scheduled for "midnight"
 *    goes out at midnight where the recipient is (spec 164).
 *  - scheduled messages can carry a card: its type, its card data, its files (kept on the scheduled
 *    row until it goes out) and the zone it was planned in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('logged_in_country_id')->comment('IANA zone of the phone');
        });

        Schema::table('chat_scheduled_messages', function (Blueprint $table) {
            $table->string('type', 20)->default('text')->after('owner_id');
            $table->json('meta')->nullable()->after('body');
            $table->string('timezone', 64)->nullable()->after('send_at')->comment('the zone "send_at" was chosen in');
        });
    }

    public function down(): void
    {
        Schema::table('chat_scheduled_messages', fn (Blueprint $table) => $table->dropColumn(['type', 'meta', 'timezone']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('timezone'));
    }
};
