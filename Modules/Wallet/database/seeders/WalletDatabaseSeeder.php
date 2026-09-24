<?php

namespace Modules\Wallet\Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Modules\Wallet\Models\WalletSetting;

class WalletDatabaseSeeder extends Seeder
{
    /**
     * Backfills wallet_settings for every existing country. Idempotent via
     * firstOrCreate, so this is safe to re-run — it also covers countries
     * created before WalletSettingObserver existed (it only fires on the
     * `created` event going forward, not retroactively).
     */
    public function run(): void
    {
        Country::query()->pluck('id')->each(
            fn (int $countryId) => WalletSetting::query()->firstOrCreate(['country_id' => $countryId]),
        );

        $this->call([
            FinancialCategorySeeder::class,
            PaymentMethodSeeder::class,
        ]);
    }
}
