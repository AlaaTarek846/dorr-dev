<?php

namespace Database\Seeders\General;

use App\Models\Country;
use App\Models\Region;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Illuminate\Database\Seeder;

/**
 * The first-level divisions of a country — governorates, states, provinces, regions — that a service can be switched on
 * or off for on its own (Dashboard → Governorates / States, Service control).
 *
 * `data/regions.json` lists them for the countries the product is built around, in English and Arabic. Other countries
 * have none until an admin adds them. It only adds: run it again and nothing is duplicated, and regions an admin added or
 * edited are left alone.
 */
class RegionSeeder extends Seeder
{
    use SyncsSeedTranslations;

    public function run(): void
    {
        $countries = Country::query()->pluck('id', 'code');

        foreach ($this->regions() as $code => $regions) {
            $countryId = $countries[strtoupper($code)] ?? null;

            // Only the countries that exist here get regions.
            if ($countryId === null) {
                continue;
            }

            foreach (array_values($regions) as $position => $names) {
                $this->seedRegion($countryId, $names, $position + 1);
            }
        }
    }

    /**
     * @param  array{en: string, ar: string}  $names
     */
    private function seedRegion(int $countryId, array $names, int $sortOrder): void
    {
        $exists = Region::query()->where('country_id', $countryId)
            ->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('name', $names['en']))
            ->exists();

        if ($exists) {
            return;
        }

        $region = Region::query()->create(['country_id' => $countryId, 'status' => true, 'sort_order' => $sortOrder]);
        $this->syncTranslations($region, ['en' => $names['en'], 'ar' => $names['ar']]);
    }

    /**
     * @return array<string, list<array{en: string, ar: string}>>
     */
    private function regions(): array
    {
        $path = database_path('seeders/data/regions.json');

        return is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }
}
