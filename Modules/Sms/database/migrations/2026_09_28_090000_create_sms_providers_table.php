<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SmsProvider — one row per registered SMS provider adapter (Twilio, SMS Misr).
 *
 * A provider holds identity + status ONLY. Its configuration SCHEMA is derived
 * live from the adapter (SmsAdapterRegistry), never stored, and the provider
 * holds NO credentials. Real credentials live on the bound sms_accounts rows.
 *
 * `configuration` and `test_*` are kept for schema compatibility with the legacy
 * shape but are intentionally left null — providers never carry secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key')->unique();
            $table->text('configuration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('test_status')->default('never_tested');
            $table->string('test_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_providers');
    }
};
