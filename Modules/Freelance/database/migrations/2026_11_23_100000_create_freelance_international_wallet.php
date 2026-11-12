<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Services\Plans\FreelanceOfferPackDefaults;
use Modules\Freelance\Services\Plans\FreelancePlanDefaults;
use Modules\Freelance\Services\Policy\FreelanceMoney;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * One currency for freelance work (owner, 2026-11-04 — `freelance.money`): the hidden international country row whose currency is
 * the freelance one (USD) holds every freelancer's and client's international wallet, the world's freelance policy and the one price
 * of each plan and pack. Creating the row also gives it its wallet settings (WalletSettingObserver: transfers off).
 *
 * Top-ups work as they do locally: every payment method linked to an active country is linked to the international row too (no
 * limits; the admin sets them on the payment method page). No amount is converted — the international wallet starts empty, and the
 * old per-country prices and policies stay in the tables, unread.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A fresh database (the tests, a new install) has no countries yet: the row is made on first use, the prices by FreelancePlansSeeder.
        if (! DB::table('countries')->exists()) {
            return;
        }
        $money = app(FreelanceMoney::class);
        $country = $money->country();

        if (DB::getSchemaBuilder()->hasTable('payment_method_country')) {
            $methods = DB::table('payment_method_country')
                ->whereIn('country_id', DB::table('countries')->where('status', true)->select('id'))
                ->where('status', true)->distinct()->pluck('payment_method_id');
            foreach ($methods as $methodId) {
                DB::table('payment_method_country')->updateOrInsert(
                    ['payment_method_id' => $methodId, 'country_id' => $country->id],
                    ['min_amount_minor' => null, 'max_amount_minor' => null, 'status' => true, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        app(FreelancePlanDefaults::class)->seed();
        app(FreelanceOfferPackDefaults::class)->seed();
        FreelancePolicies::forget();
    }

    public function down(): void
    {
        // Wallets may already hold money on the row: it stays.
    }
};
