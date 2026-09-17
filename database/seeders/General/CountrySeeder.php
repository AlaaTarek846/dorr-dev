<?php

namespace Database\Seeders\General;

use App\Enums\Status;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Database\Seeders\Concerns\TruncatesBeforeSeeding;
use Illuminate\Database\Seeder;
use Locale;

class CountrySeeder extends Seeder
{
    use SyncsSeedTranslations, TruncatesBeforeSeeding;

    /**
     * @var array<string, array{phone_starts_with: string, phone_length: int}>|null
     */
    private ?array $phoneRules = null;

    public function run(): void
    {
        $this->detachUsersFromCountries();
        $this->truncateModels(Country::class);

        $flagIds = Flag::query()->pluck('id', 'code');
        $currencyIds = Currency::query()->pluck('id', 'code');

        foreach ($this->countryDefinitions() as $data) {
            $country = Country::query()->make([
                'code' => $data['code'],
                'code_alpha3' => $data['code_alpha3'],
                'dial_code' => $data['dial_code'],
                'phone_starts_with' => $data['phone_starts_with'],
                'phone_length' => $data['phone_length'],
                'is_default' => $data['is_default'],
                'flag_id' => $flagIds[$data['flag']] ?? null,
                'currency_id' => $currencyIds[$data['currency']] ?? null,
                'status' => Status::Active,
            ]);

            $country->save();
            $this->syncTranslations($country, $data['name']);
        }
    }

    /**
     * @return list<array{code: string, code_alpha3: string|null, dial_code: string, phone_starts_with: string, phone_length: int, is_default: bool, flag: string, currency: string, name: array{en: string, ar: string}}>
     */
    private function countryDefinitions(): array
    {
        $path = database_path('seeders/data/countries.json');

        if (! is_file($path)) {
            return $this->fallbackCountries();
        }

        $countries = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $flagCodes = Flag::query()->pluck('code')->flip();
        $currencyCodes = Currency::query()->pluck('code')->flip();
        $definitions = [];

        foreach ($countries as $country) {
            $code = strtoupper(trim((string) ($country['iso2'] ?? '')));
            $flag = strtolower($code);
            $currency = strtoupper(trim((string) ($country['currency'] ?? '')));

            if ($code === '' || ! isset($flagCodes[$flag]) || $currency === '' || ! isset($currencyCodes[$currency])) {
                continue;
            }

            $englishName = trim((string) ($country['name'] ?? $code));
            $arabicName = trim((string) ($country['translations']['ar'] ?? ''));
            $arabicName = $arabicName !== ''
                ? $arabicName
                : (Locale::getDisplayRegion('-'.$code, 'ar') ?: $englishName);

            $definitions[$code] = [
                'code' => $code,
                'code_alpha3' => strtoupper(trim((string) ($country['iso3'] ?? ''))) ?: null,
                'dial_code' => $this->formatDialCode((string) ($country['phonecode'] ?? '')),
                'phone_starts_with' => $this->phoneRule($code)['phone_starts_with'],
                'phone_length' => $this->phoneRule($code)['phone_length'],
                'is_default' => $code === 'EG',
                'flag' => $flag,
                'currency' => $currency,
                'name' => [
                    'en' => $englishName,
                    'ar' => $arabicName,
                ],
            ];
        }

        uasort($definitions, fn (array $left, array $right): int => strcmp($left['name']['en'], $right['name']['en']));

        return array_values($definitions);
    }

    /**
     * @return array{phone_starts_with: string, phone_length: int}
     */
    private function phoneRule(string $code): array
    {
        $rules = $this->phoneRules();

        return $rules[$code] ?? [
            'phone_starts_with' => '0',
            'phone_length' => 10,
        ];
    }

    /**
     * @return array<string, array{phone_starts_with: string, phone_length: int}>
     */
    private function phoneRules(): array
    {
        if ($this->phoneRules !== null) {
            return $this->phoneRules;
        }

        $path = database_path('seeders/data/country-phone-rules.json');

        if (! is_file($path)) {
            return $this->phoneRules = [];
        }

        /** @var array<string, array{phone_starts_with: string, phone_length: int}> $rules */
        $rules = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $this->phoneRules = $rules;
    }

    private function formatDialCode(string $phoneCode): string
    {
        $phoneCode = preg_replace('/\D+/', '', $phoneCode) ?? '';

        return $phoneCode !== '' ? '+'.$phoneCode : '+0';
    }

    /**
     * @return list<array{code: string, code_alpha3: string, dial_code: string, phone_starts_with: string, phone_length: int, is_default: bool, flag: string, currency: string, name: array{en: string, ar: string}}>
     */
    private function fallbackCountries(): array
    {
        return [
            [
                'code' => 'EG',
                'code_alpha3' => 'EGY',
                'dial_code' => '+20',
                'phone_starts_with' => '1',
                'phone_length' => 10,
                'is_default' => true,
                'flag' => 'eg',
                'currency' => 'EGP',
                'name' => ['en' => 'Egypt', 'ar' => 'مصر'],
            ],
        ];
    }
}
