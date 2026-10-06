<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart quiet (spec 115): in the times I choose (every day or on some days, past midnight too, in
 * my time zone), messages that aren't urgent make no notification — they're counted, and one
 * summary arrives when the quiet time ends ("While it was quiet: 12 messages in 4 chats"). Calls
 * and urgent messages still come through. `quiet_scope`: every chat, or groups only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->json('quiet_schedule')->nullable()->comment('{from, to, days[], timezone}');
            $table->string('quiet_scope', 10)->default('all')->comment('all | groups');
        });

        Schema::create('chat_quiet_digests', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedInteger('messages_count')->default(0);
            $table->json('conversation_ids')->nullable();
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_quiet_digests');
        Schema::table('chat_privacy_settings', fn (Blueprint $table) => $table->dropColumn(['quiet_schedule', 'quiet_scope']));
    }
};
