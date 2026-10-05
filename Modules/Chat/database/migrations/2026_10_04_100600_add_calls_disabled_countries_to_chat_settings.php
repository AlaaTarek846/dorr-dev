<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calls switched off in some countries only (internet calls need a licence in some places):
     * nobody whose account is in one of these countries can call or be called.
     */
    public function up(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->json('calls_disabled_countries')->nullable()->after('calls_enabled')->comment('country ids where calls are off');
        });

        // ChatSetting::current() is cached forever: drop the copy that doesn't know the new column.
        Cache::forget('chat.settings');
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn('calls_disabled_countries');
        });
    }
};
