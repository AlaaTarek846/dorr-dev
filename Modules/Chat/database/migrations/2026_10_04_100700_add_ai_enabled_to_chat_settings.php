<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI inside the chat (translate, voice to text, summary, suggested replies): one switch for the
     * whole platform. They also need an AI provider set up in the AI settings.
     */
    public function up(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(true)->after('calls_disabled_countries');
        });

        // ChatSetting::current() is cached forever: drop the copy that doesn't know the new column.
        Cache::forget('chat.settings');
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn('ai_enabled');
        });
    }
};
