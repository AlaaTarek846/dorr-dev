<?php

namespace Database\Seeders;

use Database\Seeders\Admin\AdminPermissionSeeder;
use Database\Seeders\Admin\AdminSeeder;
use Database\Seeders\General\CountrySeeder;
use Database\Seeders\General\CurrencySeeder;
use Database\Seeders\General\FlagSeeder;
use Database\Seeders\General\LanguageSeeder;
use Database\Seeders\General\PlatformSettingSeeder;
use Database\Seeders\General\ServiceCategoriesSeeder;
use Database\Seeders\Provider\ProviderSeeder;
use Database\Seeders\User\UserSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;

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
            UserSeeder::class,
            PlatformSettingSeeder::class,
            ServiceCategoriesSeeder::class,
            AdminSeeder::class,
            AdminPermissionSeeder::class,
            ProviderSeeder::class,
            AIDatabaseSeeder::class,
        ]);
    }
}
