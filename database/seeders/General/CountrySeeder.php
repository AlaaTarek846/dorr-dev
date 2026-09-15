<?php

namespace Database\Seeders\General;

use App\Enums\Status;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Database\Seeders\Concerns\TruncatesBeforeSeeding;
use Illuminate\Database\Seeder;
use Locale;

class CountrySeeder extends Seeder
{
    use TruncatesBeforeSeeding;

    /**
     * @var array<string, string|null>
     */
    private array $phoneStartsWith = [
        'EG' => '1',
        'SA' => '5',
        'AE' => '5',
        'GB' => '7',
    ];

    /**
     * @var array<string, int>
     */
    private array $phoneLengths = [
        'EG' => 10,
        'SA' => 9,
        'AE' => 9,
        'BH' => 8,
        'KW' => 8,
        'QA' => 8,
        'OM' => 8,
        'JO' => 9,
        'IQ' => 10,
        'PS' => 9,
        'LB' => 8,
        'SY' => 9,
        'YE' => 9,
        'MA' => 9,
        'DZ' => 9,
        'TN' => 8,
        'LY' => 9,
        'SD' => 9,
        'US' => 10,
        'CA' => 10,
        'GB' => 10,
        'FR' => 9,
        'DE' => 11,
        'IT' => 10,
        'ES' => 9,
        'TR' => 10,
        'IN' => 10,
        'PK' => 10,
        'BD' => 10,
        'AU' => 9,
        'NZ' => 8,
        'CN' => 11,
        'JP' => 10,
        'KR' => 10,
        'BR' => 11,
        'MX' => 10,
        'RU' => 10,
        'UA' => 9,
        'NL' => 9,
        'BE' => 9,
        'SE' => 9,
        'NO' => 8,
        'DK' => 8,
        'FI' => 9,
        'PL' => 9,
        'PT' => 9,
        'GR' => 10,
        'IE' => 9,
        'CH' => 9,
        'AT' => 10,
        'CZ' => 9,
        'HU' => 9,
        'RO' => 9,
        'BG' => 9,
        'HR' => 9,
        'RS' => 9,
        'ZA' => 9,
        'NG' => 10,
        'KE' => 9,
        'GH' => 9,
        'ET' => 9,
        'PH' => 10,
        'ID' => 10,
        'MY' => 9,
        'SG' => 8,
        'TH' => 9,
        'VN' => 9,
        'AR' => 10,
        'CL' => 9,
        'CO' => 10,
        'PE' => 9,
    ];

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

            $country->fillAllTranslations(['name' => $data['name']]);
            $country->save();
        }
    }

    /**
     * @return list<array{code: string, code_alpha3: string|null, dial_code: string, phone_starts_with: string|null, phone_length: int|null, is_default: bool, flag: string, currency: string, name: array{en: string, ar: string}}>
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
                'phone_starts_with' => $this->phoneStartsWith[$code] ?? null,
                'phone_length' => $this->phoneLengths[$code] ?? null,
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

    private function formatDialCode(string $phoneCode): string
    {
        $phoneCode = preg_replace('/\D+/', '', $phoneCode) ?? '';

        return $phoneCode !== '' ? '+'.$phoneCode : '+0';
    }

    /**
     * @return list<array{code: string, code_alpha3: string, dial_code: string, phone_starts_with: string|null, phone_length: int|null, is_default: bool, flag: string, currency: string, name: array{en: string, ar: string}}>
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
