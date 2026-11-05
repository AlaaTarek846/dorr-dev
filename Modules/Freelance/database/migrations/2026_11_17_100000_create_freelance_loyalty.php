<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-L09 (part B): a freelancer's loyalty program — a discount for clients who keep coming back, in steps by how many works they finished
 * together. The freelancer sets it (it is their price); the platform sets the limits. Where a discount was taken, the offer and the
 * consultation keep the usual price and the percent beside the price paid, so the numbers on both sides stay explainable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->unique()->constrained('freelancers')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('tiers')->comment('[{after: finished works together, discount_bp}], the first step first');
            $table->timestamps();
        });

        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->unsignedBigInteger('list_price_minor')->nullable()->after('price_minor')->comment('the price asked before the loyalty discount');
            $table->unsignedSmallInteger('loyalty_bp')->nullable()->after('list_price_minor')->comment('the loyalty discount taken (basis points)');
        });

        Schema::table('freelance_consultations', function (Blueprint $table) {
            $table->unsignedBigInteger('list_price_minor')->nullable()->after('price_minor')->comment('the price of the length before the loyalty discount');
            $table->unsignedSmallInteger('loyalty_bp')->nullable()->after('list_price_minor')->comment('the loyalty discount taken (basis points)');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_consultations', function (Blueprint $table) {
            $table->dropColumn(['list_price_minor', 'loyalty_bp']);
        });
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->dropColumn(['list_price_minor', 'loyalty_bp']);
        });
        Schema::dropIfExists('freelance_loyalty_programs');
    }
};
