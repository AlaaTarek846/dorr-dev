<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A device the wallet has already opened from once, after proving the phone on file is reachable
     * from it (wallet policy bend 3 — device trust). `device_id` is a random id the app generates once
     * and keeps locally (not a hardware identifier) — it says "the same app install asked before", not
     * "this exact phone", which is the most a client-supplied id can honestly claim.
     */
    public function up(): void
    {
        Schema::create('wallet_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->comment('alias المالك (user/provider)');
            $table->unsignedBigInteger('owner_id');
            $table->string('device_id');
            $table->timestamp('trusted_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'device_id'], 'wallet_trusted_devices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_trusted_devices');
    }
};
