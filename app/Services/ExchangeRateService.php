<?php

namespace App\Services;

use App\Models\Currency;
use App\Repositories\CurrencyRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExchangeRateService
{
    public function __construct(protected CurrencyRepository $currencyRepository) {}

    public function syncIfStale(bool $force = false): int
    {
        $cacheKey = $this->cacheKey();

        if ($force) {
            Cache::forget($cacheKey);
        }

        if (Cache::has($cacheKey)) {
            return (int) Cache::get($cacheKey);
        }

        $updated = $this->sync();

        Cache::put($cacheKey, $updated, config('exchange.cache_ttl', 3600));

        return $updated;
    }

    public function sync(): int
    {
        $baseCode = $this->resolveBaseCurrencyCode();
        $rates = $this->fetchRates($baseCode);
        $updated = 0;

        Currency::query()
            ->select(['id', 'code'])
            ->orderBy('id')
            ->chunkById(100, function ($currencies) use ($rates, $baseCode, &$updated) {
                foreach ($currencies as $currency) {
                    $code = strtoupper((string) $currency->code);
                    $rate = $this->resolveRateForCurrency($code, $baseCode, $rates);

                    if ($rate === null) {
                        continue;
                    }

                    Currency::query()
                        ->whereKey($currency->id)
                        ->update(['exchange_rate' => $rate]);

                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @return array<string, float>
     */
    protected function fetchRates(string $baseCode): array
    {
        $provider = config('exchange.provider', 'open_er_api');

        return match ($provider) {
            'open_er_api' => $this->fetchFromOpenErApi($baseCode),
            default => throw new RuntimeException("Unsupported exchange rate provider [{$provider}]."),
        };
    }

    /**
     * @return array<string, float>
     */
    protected function fetchFromOpenErApi(string $baseCode): array
    {
        $baseCode = strtoupper($baseCode);
        $url = rtrim((string) config('exchange.open_er_api_url'), '/')."/{$baseCode}";

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException('Exchange rate API request failed.');
            }

            $payload = $response->json();

            if (($payload['result'] ?? null) !== 'success') {
                throw new RuntimeException('Exchange rate API returned an unsuccessful result.');
            }

            $rates = $payload['rates'] ?? [];

            if (! is_array($rates) || $rates === []) {
                throw new RuntimeException('Exchange rate API returned no rates.');
            }

            /** @var array<string, float> $normalized */
            $normalized = [];

            foreach ($rates as $code => $rate) {
                if (! is_numeric($rate)) {
                    continue;
                }

                $normalized[strtoupper((string) $code)] = (float) $rate;
            }

            return $normalized;
        } catch (\Throwable $exception) {
            Log::warning('Failed to fetch exchange rates.', [
                'base' => $baseCode,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    protected function resolveBaseCurrencyCode(): string
    {
        $configured = config('exchange.base_currency');

        if (is_string($configured) && $configured !== '') {
            return strtoupper($configured);
        }

        $default = $this->currencyRepository->defaultCurrency();

        if ($default?->code) {
            return strtoupper((string) $default->code);
        }

        return 'EGP';
    }

    /**
     * @param  array<string, float>  $rates
     */
    protected function resolveRateForCurrency(string $code, string $baseCode, array $rates): ?float
    {
        if ($code === $baseCode) {
            return 1.0;
        }

        if (! array_key_exists($code, $rates)) {
            return null;
        }

        return round((float) $rates[$code], 8);
    }

    protected function cacheKey(): string
    {
        return 'exchange_rates.synced.'.$this->resolveBaseCurrencyCode();
    }
}
