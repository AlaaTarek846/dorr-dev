<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A quick-pick list of "people I've already sent money to" (wallet policy bend 10) — one row per
     * (sender, country, recipient): a transfer is always same-country/same-currency, so the list is
     * scoped the same way. Written automatically on every successful transfer, never by hand.
     */
    public function up(): void
    {
        Schema::create('wallet_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->comment('alias المرسل (user)');
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('beneficiary_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('first_added_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'country_id', 'beneficiary_user_id'], 'wallet_beneficiaries_unique');
            $table->index(['owner_type', 'owner_id', 'country_id', 'last_used_at'], 'wallet_beneficiaries_owner_recency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_beneficiaries');
    }
};
