<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group moderation: slow mode (members wait between messages; admins don't), words the admins
     * banned (a message containing one isn't sent), and an invite link that stops working at a set time.
     */
    public function up(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->unsignedInteger('slow_mode_seconds')->default(0)->after('approve_joins')->comment('0 = off; members wait this long between messages');
            $table->json('banned_words')->nullable()->after('slow_mode_seconds')->comment('messages containing one of these are refused (admins exempt)');
            $table->timestamp('invite_expires_at')->nullable()->after('invite_token')->comment('null = the link never expires');
        });
    }

    public function down(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropColumn(['slow_mode_seconds', 'banned_words', 'invite_expires_at']);
        });
    }
};
