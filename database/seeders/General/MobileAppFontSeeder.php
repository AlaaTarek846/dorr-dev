<?php

namespace Database\Seeders\General;

use App\Models\MobileAppFont;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Illuminate\Database\Seeder;

class MobileAppFontSeeder extends Seeder
{
    use SyncsSeedTranslations;

    public function run(): void
    {
        $font = MobileAppFont::query()->firstOrCreate(
            ['slug' => 'dorr'],
            [
                'status' => true,
                'is_default' => true,
                'sort_order' => 0,
            ],
        );

        $this->syncTranslations($font, [
            'en' => 'DORR',
            'ar' => 'دور',
        ]);

        MobileAppFont::query()
            ->where('id', '!=', $font->id)
            ->update(['is_default' => false]);
    }
}
