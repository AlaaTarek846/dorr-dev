<?php

namespace Database\Seeders;

use Database\Seeders\Admin\AdminPermissionSeeder;
use Database\Seeders\Admin\AdminSeeder;
use Database\Seeders\General\CountrySeeder;
use Database\Seeders\General\CurrencySeeder;
use Database\Seeders\General\DashboardThemeSeeder;
use Database\Seeders\General\FaqSeeder;
use Database\Seeders\General\FlagSeeder;
use Database\Seeders\General\LanguageSeeder;
use Database\Seeders\General\LegalPageSeeder;
use Database\Seeders\General\MobileAppColorDefaultSeeder;
use Database\Seeders\General\MobileAppFontSeeder;
use Database\Seeders\General\PlatformSettingSeeder;
use Database\Seeders\General\ServiceCategoriesSeeder;
use Database\Seeders\Provider\ProviderSeeder;
use Database\Seeders\User\UserSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Chat\Database\Seeders\ChatDatabaseSeeder;
use Modules\User\Database\Seeders\SupportDatabaseSeeder;
use Modules\Wallet\Database\Seeders\WalletDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            FlagSeeder::class,
            LanguageSeeder::class,
            CurrencySeeder::class,
            CountrySeeder::class,
            WalletDatabaseSeeder::class,
            ChatDatabaseSeeder::class,
            \Modules\Discover\Database\Seeders\DiscoverSeeder::class,
            \Modules\Sports\Database\Seeders\SportsSeeder::class,
            UserSeeder::class,
            PlatformSettingSeeder::class,
            MobileAppColorDefaultSeeder::class,
            MobileAppFontSeeder::class,
            DashboardThemeSeeder::class,
            ServiceCategoriesSeeder::class,
            FaqSeeder::class,
            LegalPageSeeder::class,
            AdminSeeder::class,
            AdminPermissionSeeder::class,
            ProviderSeeder::class,
            AIDatabaseSeeder::class,
            SupportDatabaseSeeder::class,
        ]);
    }
}
