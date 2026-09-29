<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SmsAccount — one usable SMS account bound to a provider. It carries the ACTUAL
 * per-account credentials in `configuration` (encrypted server-side only) and is
 * the single source of truth for them. There is no provider-level fallback.
 *
 * An account is usable only when: provider.is_active = true AND
 * account.is_active = true AND account.test_status = 'passed'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('provider_id')->constrained('sms_providers')->cascadeOnDelete();
            $table->string('sender')->nullable();
            $table->string('sender_code')->nullable();
            $table->string('sender_type')->nullable();
            // Encrypted JSON of the provider configuration (never exposed raw).
            $table->text('configuration')->nullable();
            $table->string('purpose')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('test_status')->default('never_tested');
            $table->string('test_error')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index('is_default');
            $table->index('test_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_accounts');
    }
};
