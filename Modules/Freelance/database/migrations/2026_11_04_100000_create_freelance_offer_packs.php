<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extra offers for sale (FL-L01, phase 2): the packs (10 / 25 / 50 offers…), their price per country, and what each freelancer bought —
 * a credit batch that is spent after the month's free offers are used up and ends on a date. An offer sent on a credit remembers which
 * batch paid for it (`credit_id`), so taking it back before the client saw it gives the credit back. Also the ledger line the money is
 * booked under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_offer_packs', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->json('name');
            $table->unsignedInteger('offers')->comment('how many offers the pack adds');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('freelance_offer_pack_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pack_id')->constrained('freelance_offer_packs')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor')->comment('minor units of the country currency');
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->unique(['pack_id', 'country_id']);
        });

        Schema::create('freelance_offer_credits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('pack_id')->nullable()->constrained('freelance_offer_packs')->nullOnDelete();
            $table->foreignId('country_id')->constrained('countries');
            // The app's id for this tap: the same one twice is the same purchase, never charged twice.
            $table->string('request_id', 64)->nullable();
            $table->unsignedInteger('quantity')->comment('offers bought; what is left is worked out from the offers sent on it');
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency_code', 8);
            $table->foreignId('wallet_transaction_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['freelancer_id', 'request_id']);
            $table->index(['freelancer_id', 'expires_at']);
        });

        Schema::table('freelance_offers', function (Blueprint $table): void {
            $table->foreignId('credit_id')->nullable()->after('free')->constrained('freelance_offer_credits')->nullOnDelete();
        });

        if (Schema::hasTable('financial_categories') && ! DB::table('financial_categories')->where('slug', 'freelance_offer_packs')->exists()) {
            $id = DB::table('financial_categories')->insertGetId([
                'slug' => 'freelance_offer_packs', 'type' => 'income', 'is_system' => true, 'status' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (Schema::hasTable('financial_category_translations')) {
                foreach (['en' => 'Freelance extra offers', 'ar' => 'عروض إضافية للعمل الحر'] as $locale => $name) {
                    DB::table('financial_category_translations')->insert([
                        'financial_category_id' => $id, 'locale' => $locale, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('freelance_offers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('credit_id');
        });
        Schema::dropIfExists('freelance_offer_credits');
        Schema::dropIfExists('freelance_offer_pack_prices');
        Schema::dropIfExists('freelance_offer_packs');
        if (Schema::hasTable('financial_categories')) {
            $id = DB::table('financial_categories')->where('slug', 'freelance_offer_packs')->value('id');
            if ($id !== null) {
                if (Schema::hasTable('financial_category_translations')) {
                    DB::table('financial_category_translations')->where('financial_category_id', $id)->delete();
                }
                DB::table('financial_categories')->where('id', $id)->delete();
            }
        }
    }
};
