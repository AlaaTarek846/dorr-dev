<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An iPhone's PushKit (VoIP) token, next to its OneSignal player id. A ringing chat call goes to it
     * straight through APNs so the iPhone rings full screen (CallKit) even locked or closed — what
     * Android does with its full-screen notification. OneSignal doesn't carry VoIP pushes.
     */
    public function up(): void
    {
        Schema::table('notification_devices', function (Blueprint $table) {
            $table->string('voip_token', 200)->nullable()->after('player_id')->comment('iPhone PushKit token — ringing calls go to it through APNs');
        });
    }

    public function down(): void
    {
        Schema::table('notification_devices', function (Blueprint $table) {
            $table->dropColumn('voip_token');
        });
    }
};
