<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A one-time "build me a site" product for people without a plan (for
     * example a standalone portfolio). The price is set per country by the
     * admin; a country without a price row simply cannot buy the offer.
     */
    public function up(): void
    {
        Schema::create('ai_site_offers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('generations_included')->default(20)
                ->comment('عدد مرات البناء/التعديل المشمولة في السعر');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_site_offer_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('ai_site_offers')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->unique(['offer_id', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_site_offer_prices');
        Schema::dropIfExists('ai_site_offers');
    }
};
