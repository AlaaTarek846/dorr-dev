<?php

namespace Modules\Sports\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Models\SportsSport;

/**
 * The provider's sports (football on, the rest off until the admin turns them on) and the
 * settings row. Safe to run again. Competitions come from `php artisan sports:import football`.
 */
class SportsSeeder extends Seeder
{
    public function run(): void
    {
        SportsSetting::query()->firstOrCreate([]);
        foreach (array_keys((array) config('sports.sports', [])) as $i => $key) {
            SportsSport::query()->firstOrCreate(['key' => $key], [
                'status' => $key === 'football',
                'min_share_percent' => $key === 'football' ? 40 : 5,
                'sort_order' => $i + 1,
            ]);
        }
    }
}
