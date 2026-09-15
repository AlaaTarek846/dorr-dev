<?php

namespace Database\Seeders\General;

use App\Enums\Status;
use App\Enums\TextDirection;
use App\Models\Flag;
use App\Models\Language;
use Database\Seeders\Concerns\TruncatesBeforeSeeding;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    use TruncatesBeforeSeeding;

    public function run(): void
    {
        $this->truncateModels(Language::class);

        $egyptFlag = Flag::query()->where('code', 'eg')->value('id');
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
            'flag_id' => $egyptFlag,
        ]);
        $arabic->fillAllTranslations([
            'name' => [
                'en' => 'Arabic',
                'ar' => 'العربية',
            ],
        ]);
        $arabic->save();

        $english = Language::query()->firstOrNew(['code' => 'en']);
        $english->fill([
            'direction' => TextDirection::Ltr,
            'is_default_website' => true,
            'is_default_dashboard' => true,
            'stores_translation' => true,
            'status' => Status::Active,
            'flag_id' => $usFlag,
        ]);
        $english->fillAllTranslations([
            'name' => [
                'en' => 'English',
                'ar' => 'الإنجليزية',
            ],
        ]);
        $english->save();

        $german = Language::query()->firstOrNew(['code' => 'de']);
        $german->fill([
            'direction' => TextDirection::Ltr,
            'is_default_website' => false,
            'is_default_dashboard' => false,
            'stores_translation' => false,
            'status' => Status::Active,
            'flag_id' => $germanFlag,
        ]);
        $german->fillAllTranslations([
            'name' => [
                'en' => 'German',
                'ar' => 'الألمانية',
            ],
        ]);
        $german->save();

        $french = Language::query()->firstOrNew(['code' => 'fr']);
        $french->fill([
            'direction' => TextDirection::Ltr,
            'is_default_website' => false,
            'is_default_dashboard' => false,
            'stores_translation' => false,
            'status' => Status::Active,
            'flag_id' => $frenchFlag,
        ]);
        $french->fillAllTranslations([
            'name' => [
                'en' => 'French',
                'ar' => 'الفرنسية',
            ],
        ]);
        $french->save();
    }
}
