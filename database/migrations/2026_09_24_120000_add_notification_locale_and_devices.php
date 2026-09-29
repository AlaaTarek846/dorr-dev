<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two things a notification needs to reach a person *in their own language*:
     *
     *  - `locale` on every kind of account — the language they last used the app/dashboard in. A
     *    real-time (Pusher) message is composed at send time, by the *sender's* request, so without
     *    this it would come out in whatever language the person who triggered it happens to use
     *    (an admin approving a withdrawal in English would push English to an Arabic provider).
     *  - `notification_devices` — the OneSignal player ids of a person's phones, so a push can be sent
     *    even when the app is closed (one person, several devices).
     */
    public function up(): void
    {
        foreach (['users', 'providers', 'admins'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('locale', 10)->nullable()->comment('آخر لغة استخدمها — بيتبعت بيها الإشعار');
            });
        }

        Schema::create('notification_devices', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->comment('morph: user / provider');
            $table->unsignedBigInteger('owner_id');
            $table->string('player_id')->unique()->comment('OneSignal player / subscription id');
            $table->string('platform', 16)->nullable()->comment('android | ios | web');
            $table->string('locale', 10)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_devices');

        foreach (['users', 'providers', 'admins'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('locale');
            });
        }
    }
};
