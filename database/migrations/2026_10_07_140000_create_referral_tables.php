<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('referrable_type', 32);
            $table->unsignedBigInteger('referrable_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['referrable_type', 'referrable_id']);
            $table->index('is_active');
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->string('referrer_type', 32);
            $table->unsignedBigInteger('referrer_id');
            $table->string('referred_type', 32);
            $table->unsignedBigInteger('referred_id');
            $table->foreignId('referral_code_id')->constrained('referral_codes')->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_type', 'referrer_id']);
            $table->unique(['referred_type', 'referred_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
    }
};
