<?php

namespace Database\Seeders\General;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Language;
use Illuminate\Database\Seeder;
use Locale;

class BackfillCatalogTranslationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->backfillCountries();
        $this->backfillCurrencies();
        $this->backfillLanguages();
    }

    private function backfillCountries(): void
    {
        $path = database_path('seeders/data/countries.json');

        if (! is_file($path)) {
            return;
        }

        $countries = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($countries as $countryData) {
            $code = strtoupper(trim((string) ($countryData['iso2'] ?? '')));

            if ($code === '') {
                continue;
            }

            $country = Country::query()->where('code', $code)->first();

            if (! $country) {
                continue;
            }

            $englishName = trim((string) ($countryData['name'] ?? $code));
            $arabicName = trim((string) ($countryData['translations']['ar'] ?? ''));
            $arabicName = $arabicName !== ''
                ? $arabicName
                : (Locale::getDisplayRegion('-'.$code, 'ar') ?: $englishName);

            $this->syncNameTranslations($country, [
                'en' => $englishName,
                'ar' => $arabicName,
            ]);
        }
    }

    private function backfillCurrencies(): void
    {
        $path = database_path('seeders/data/countries.json');

        if (! is_file($path)) {
            return;
        }

        $countries = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $definitions = [];

        foreach ($countries as $countryData) {
            $code = strtoupper(trim((string) ($countryData['currency'] ?? '')));

            if ($code === '' || isset($definitions[$code])) {
                continue;
            }

            $englishName = trim((string) ($countryData['currency_name'] ?? $code));
            $arabicName = locale_get_display_name($code, 'ar') ?: $englishName;

            $definitions[$code] = [
                'en' => $englishName,
                'ar' => $arabicName,
            ];
        }

        foreach ($definitions as $code => $names) {
            $currency = Currency::query()->where('code', $code)->first();

            if (! $currency) {
                continue;
            }

            $this->syncNameTranslations($currency, $names);
        }
    }

    private function backfillLanguages(): void
    {
        $definitions = [
            'ar' => ['en' => 'Arabic', 'ar' => 'العربية'],
            'en' => ['en' => 'English', 'ar' => 'الإنجليزية'],
            'de' => ['en' => 'German', 'ar' => 'الألمانية'],
            'fr' => ['en' => 'French', 'ar' => 'الفرنسية'],
        ];

        foreach ($definitions as $code => $names) {
            $language = Language::query()->where('code', $code)->first();

            if (! $language) {
                continue;
            }

            $this->syncNameTranslations($language, $names);
        }
    }

    /**
     * @param  array{en: string, ar: string}  $names
     */
    private function syncNameTranslations(object $model, array $names): void
    {
        foreach ($names as $locale => $name) {
            $model->translations()->updateOrCreate(
                ['locale' => $locale],
                ['name' => $name],
            );
        }
    }
}
