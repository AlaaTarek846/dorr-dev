<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From the WhatsApp list in the spec's backlog: a default timer for the chats I start (58),
 * silencing calls from numbers I never saved (60), and hiding my "about" status from some of my
 * contacts (54).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->unsignedInteger('default_disappearing_seconds')->nullable()->comment('New chats I start disappear after this (86400 · 604800 · 7776000)');
            $table->boolean('silence_unknown_callers')->default(false)->comment('Calls from people I never saved do not ring');
            $table->json('status_except')->nullable()->comment('Participant keys my status (about) is hidden from');
        });

        Schema::table('chat_call_participants', function (Blueprint $table) {
            $table->boolean('silenced')->default(false)->comment('Did not ring: an unknown caller, silenced by my setting');
        });
    }

    public function down(): void
    {
        Schema::table('chat_call_participants', function (Blueprint $table) {
            $table->dropColumn('silenced');
        });
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->dropColumn(['default_disappearing_seconds', 'silence_unknown_callers', 'status_except']);
        });
    }
};
