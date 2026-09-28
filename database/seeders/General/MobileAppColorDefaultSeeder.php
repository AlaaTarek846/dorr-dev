<?php

namespace Database\Seeders\General;

use App\Models\MobileAppColorDefault;
use App\Support\Mobile\MobileColorTokens;
use Illuminate\Database\Seeder;

class MobileAppColorDefaultSeeder extends Seeder
{
    public function run(): void
    {
        MobileAppColorDefault::query()->updateOrCreate(
            ['slug' => 'platform-default'],
            [
                'is_active' => true,
                'light_tokens' => MobileColorTokens::seededLightTokens(),
                'dark_tokens' => MobileColorTokens::seededDarkTokens(),
                'light_gradients' => null,
                'dark_gradients' => null,
            ],
        );
    }
}
