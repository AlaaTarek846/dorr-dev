<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a chat push shows, in three steps instead of on / off:
     * `all` (name and message) · `name` (name, "New message") · `none` ("Dorr", "New message").
     * The old switch carries over: on → all, off → none.
     */
    public function up(): void
    {
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->string('notification_privacy', 8)->default('all')->after('notification_preview')->comment('all | name | none');
        });

        DB::table('chat_privacy_settings')->where('notification_preview', false)->update(['notification_privacy' => 'none']);

        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->dropColumn('notification_preview');
        });
    }

    public function down(): void
    {
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->boolean('notification_preview')->default(true)->after('block_screenshots');
        });

        DB::table('chat_privacy_settings')->where('notification_privacy', '!=', 'all')->update(['notification_preview' => false]);

        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->dropColumn('notification_privacy');
        });
    }
};
