<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account security (spec items 27–29):
 *  - `account_sessions`: one row per signed-in device (each holds its own Sanctum token), so an account can be open on
 *    several devices, see them all and sign any of them out.
 *  - `security_events`: the account's security log — sign-ins, failed codes, devices signed out, PIN locks…
 *  - `notification_devices.device_id`: which device a push id belongs to, so signing a device out also stops its pushes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_sessions', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            // personal_access_tokens.id — the token this device signs in with (deleted when it is signed out).
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('device_id', 64)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('ip', 45)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('revoked_at')->nullable();
            // logout | user | others | limit | admin | replaced
            $table->string('revoked_reason', 20)->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'revoked_at']);
            $table->index(['owner_type', 'owner_id', 'device_id']);
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('type', 40);
            $table->foreignId('account_session_id')->nullable()->constrained('account_sessions')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 20)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['owner_type', 'owner_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        Schema::table('notification_devices', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->after('player_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_devices', function (Blueprint $table) {
            $table->dropIndex(['device_id']);
            $table->dropColumn('device_id');
        });

        Schema::dropIfExists('security_events');
        Schema::dropIfExists('account_sessions');
    }
};
