<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hidden members: a paid group setting. The members' numbers (and @usernames) are hidden from
 * each other and nobody can start a private chat with someone they only know from that group;
 * names still show in the chat. Priced per country from the chat settings screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->timestamp('members_hidden_at')->nullable()->after('allowed_reactions')
                ->comment('When hidden members was paid for — set once, never switched off (the members were promised it)');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->boolean('hidden_members_enabled')->default(true)->after('public_stories_free');
            $table->json('hidden_members_prices')->nullable()->after('hidden_members_enabled')
                ->comment('[{country_id, amount_minor}] in that country\'s currency — not sold where a country has no price');
        });
    }

    public function down(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropColumn('members_hidden_at');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn(['hidden_members_enabled', 'hidden_members_prices']);
        });
    }
};
