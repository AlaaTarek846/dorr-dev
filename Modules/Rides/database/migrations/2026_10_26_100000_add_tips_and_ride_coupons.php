<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 2 of docs/rides_tasks.md:
 *  - rides.tip_minor / tipped_at — the rider's tip after the ride (R-144), all of it the driver's;
 *  - wallet_coupon_redemptions.ride_id — a coupon spent on a ride's quote (R-45), not on a checkout,
 *    so checkout_id may be empty now;
 *  - the ledger categories for DORR's ride fees (R-47 customer fee, R-48 payment-method fee).
 */
return new class extends Migration
{
    private const CATEGORIES = [
        ['trip_customer_fees', 'income', 'Ride customer fees', 'رسوم العميل للرحلات'],
        ['trip_payment_fees', 'income', 'Ride payment fees', 'رسوم وسيلة الدفع للرحلات'],
    ];

    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedBigInteger('tip_minor')->nullable()->after('previous_balance_minor')->comment('الإكرامية بعد الرحلة — كلها للسائق');
            $table->timestamp('tipped_at')->nullable()->after('tip_minor');
        });

        Schema::table('wallet_coupon_redemptions', function (Blueprint $table) {
            $table->unsignedBigInteger('checkout_id')->nullable()->change();
            $table->foreignId('ride_id')->nullable()->after('checkout_id')->constrained('rides')->cascadeOnDelete();
            $table->unique(['coupon_id', 'ride_id']);
        });

        if (! Schema::hasTable('financial_categories')) {
            return;
        }
        foreach (self::CATEGORIES as [$slug, $type, $en, $ar]) {
            if (DB::table('financial_categories')->where('slug', $slug)->exists()) {
                continue;
            }
            $id = DB::table('financial_categories')->insertGetId([
                'slug' => $slug, 'type' => $type, 'is_system' => true, 'status' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (Schema::hasTable('financial_category_translations')) {
                foreach (['en' => $en, 'ar' => $ar] as $locale => $name) {
                    DB::table('financial_category_translations')->insert([
                        'financial_category_id' => $id, 'locale' => $locale, 'name' => $name,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('wallet_coupon_redemptions', function (Blueprint $table) {
            $table->dropUnique(['coupon_id', 'ride_id']);
            $table->dropConstrainedForeignId('ride_id');
        });
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['tip_minor', 'tipped_at']);
        });
        // Ledger categories may already be referenced by entries: kept on rollback.
    }
};
