<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watch & Earn D20 (owner, 2026-11-02): rewards are spent anywhere in the app; whether they may also be sent to
 * another user is the admin's choice for each country. Off: spent in the app only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_settings', function (Blueprint $table) {
            $table->boolean('rewards_transferable')->default(false)->after('transfers_enabled')->comment('رصيد المكافآت قابل للتحويل لمستخدم آخر');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_settings', fn (Blueprint $table) => $table->dropColumn('rewards_transferable'));
    }
};
