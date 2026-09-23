<?php

namespace Database\Seeders\General;

use App\Enums\Status;
use App\Enums\TextDirection;
use App\Models\Flag;
use App\Models\Language;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Database\Seeders\Concerns\TruncatesBeforeSeeding;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    use SyncsSeedTranslations, TruncatesBeforeSeeding;

    public function run(): void
    {
        $this->truncateModels(Language::class);

        $saudiFlag = Flag::query()->where('code', 'sa')->value('id');
        $usFlag = Flag::query()->where('code', 'us')->value('id');
        $germanFlag = Flag::query()->where('code', 'de')->value('id');
        $frenchFlag = Flag::query()->where('code', 'fr')->value('id');

        $arabic = Language::query()->firstOrNew(['code' => 'ar']);
        $arabic->fill([
            'direction' => TextDirection::Rtl,
            'is_default_website' => false,
            'is_default_dashboard' => false,
            'stores_translation' => true,
            'status' => Status::Active,
            'flag_id' => $saudiFlag,
        ]);
        $arabic->save();
        $this->syncTranslations($arabic, [
            'en' => 'Arabic',
            'ar' => 'العربية',
        ]);

        $english = Language::query()->firstOrNew(['code' => 'en']);
        $english->fill([
            'direction' => TextDirection::Ltr,
            'is_default_website' => true,
            'is_default_dashboard' => true,
            'stores_translation' => true,
            'status' => Status::Active,
            'flag_id' => $usFlag,
        ]);
        $english->save();
        $this->syncTranslations($english, [
            'en' => 'English',
            'ar' => 'الإنجليزية',
        ]);

        $german = Language::query()->firstOrNew(['code' => 'de']);
        $german->fill([
            'direction' => TextDirection::Ltr,
            'is_default_website' => false,
            'is_default_dashboard' => false,
            'stores_translation' => false,
            'status' => Status::Active,
            'flag_id' => $germanFlag,
        ]);
        $german->save();
        $this->syncTranslations($german, [
            'en' => 'German',
            'ar' => 'الألمانية',
        ]);

        $french = Language::query()->firstOrNew(['code' => 'fr']);
        $french->fill([
            'direction' => TextDirection::Ltr,
            'is_default_website' => false,
            'is_default_dashboard' => false,
            'stores_translation' => false,
            'status' => Status::Active,
            'flag_id' => $frenchFlag,
        ]);
        $french->save();
        $this->syncTranslations($french, [
            'en' => 'French',
            'ar' => 'الفرنسية',
        ]);
    }
}
