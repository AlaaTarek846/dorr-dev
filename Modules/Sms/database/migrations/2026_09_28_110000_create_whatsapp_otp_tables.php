<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapps', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            // Encrypted Meta credentials.
            $table->text('access_token')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->string('api_version')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_available')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('test_status')->default('never_tested');
            $table->text('test_error')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['whatsapp_id', 'country_id']);
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_id')->constrained()->cascadeOnDelete();
            $table->string('template_name');
            $table->string('language');
            $table->string('meta_status')->default('unknown');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('test_error')->nullable();
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('otp_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('preferred_channel')->default('whatsapp');
            $table->string('fallback_channel')->default('sms');
            $table->integer('otp_length')->default(6);
            $table->integer('expiration_minutes')->default(5);
            $table->integer('resend_cooldown_seconds')->default(30);
            $table->integer('max_attempts')->default(3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_settings');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_countries');
        Schema::dropIfExists('whatsapps');
    }
};
