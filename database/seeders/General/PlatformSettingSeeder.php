<?php

namespace Database\Seeders\General;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class PlatformSettingSeeder extends Seeder
{
    public function run(): void
    {
        PlatformSetting::query()->firstOrCreate(
            ['id' => 1],
            ['app_name' => (string) config('app.name', 'Dorr')],
        );
    }
}
