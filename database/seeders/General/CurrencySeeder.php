<?php

namespace Database\Seeders\General;

use App\Enums\Status;
use App\Models\Country;
use App\Models\Currency;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Database\Seeders\Concerns\TruncatesBeforeSeeding;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    use SyncsSeedTranslations, TruncatesBeforeSeeding;

    /**
     * @var array<string, float>
     */
    private array $exchangeRates = [
        'EGP' => 1,
        'USD' => 0.02040816,
        'EUR' => 0.01886792,
        'SAR' => 0.07662835,
        'AED' => 0.07496252,
        'GBP' => 0.01618123,
    ];

    public function run(): void
    {
        $this->detachUsersFromCountries();
        $this->truncateModels(Country::class, Currency::class);

        foreach ($this->currencyDefinitions() as $data) {
            $currency = Currency::query()->make([
                'code' => $data['code'],
                'symbol' => $data['symbol'],
                'decimal_places' => $data['decimal_places'],
                'exchange_rate' => $data['exchange_rate'],
                'is_default' => $data['is_default'],
                'status' => Status::Active,
            ]);

            $currency->save();
            $this->syncTranslations($currency, $data['name']);
        }
    }

    /**
     * @return list<array{code: string, symbol: string, decimal_places: int, exchange_rate: float, is_default: bool, name: array{en: string, ar: string}}>
     */
    private function currencyDefinitions(): array
    {
        $path = database_path('seeders/data/countries.json');

        if (! is_file($path)) {
            return $this->fallbackCurrencies();
        }

        $countries = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $definitions = [];

        foreach ($countries as $country) {
            $code = strtoupper(trim((string) ($country['currency'] ?? '')));

            if ($code === '' || isset($definitions[$code])) {
                continue;
            }

            $englishName = trim((string) ($country['currency_name'] ?? $code));
            $arabicName = locale_get_display_name($code, 'ar') ?: $englishName;

            $definitions[$code] = [
                'code' => $code,
                'symbol' => trim((string) ($country['currency_symbol'] ?? $code)) ?: $code,
                'decimal_places' => $this->decimalPlacesFor($code),
                'exchange_rate' => $this->exchangeRates[$code] ?? 0.01,
                'is_default' => $code === 'EGP',
                'name' => [
                    'en' => $englishName,
                    'ar' => $arabicName,
                ],
            ];
        }

        ksort($definitions);

        return array_values($definitions);
    }

    private function decimalPlacesFor(string $code): int
    {
        return match ($code) {
            'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' => 0,
            'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' => 3,
            default => 2,
        };
    }

    /**
     * @return list<array{code: string, symbol: string, decimal_places: int, exchange_rate: float, is_default: bool, name: array{en: string, ar: string}}>
     */
    private function fallbackCurrencies(): array
    {
        return [
            [
                'code' => 'EGP',
                'symbol' => 'E£',
                'decimal_places' => 2,
                'exchange_rate' => 1,
                'is_default' => true,
                'name' => ['en' => 'Egyptian Pound', 'ar' => 'جنيه مصري'],
            ],
            [
                'code' => 'USD',
                'symbol' => '$',
                'decimal_places' => 2,
                'exchange_rate' => 0.02040816,
                'is_default' => false,
                'name' => ['en' => 'US Dollar', 'ar' => 'دولار أمريكي'],
            ],
        ];
    }
}
