<?php

namespace Database\Seeders\General;

use App\Enums\Status;
use App\Models\DashboardTheme;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Database\Seeders\Concerns\TruncatesBeforeSeeding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardThemeSeeder extends Seeder
{
    use SyncsSeedTranslations, TruncatesBeforeSeeding;

    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('dashboard_theme_preferences')->truncate();
        Schema::enableForeignKeyConstraints();

        $this->truncateModels(DashboardTheme::class);

        $theme = DashboardTheme::query()->create([
            'slug' => 'default',
            'path' => 'theme-1',
            'status' => Status::Active,
            'is_default' => true,
            'sort_order' => 0,
        ]);

        $this->syncTranslations($theme, [
            'en' => 'Default theme',
            'ar' => 'الثيم الافتراضي',
        ]);
    }
}
