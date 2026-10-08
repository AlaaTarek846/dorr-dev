<?php

namespace Modules\Sports\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Sports\Models\SportsSetting;
use RuntimeException;

/**
 * API-Sports: one API per sport, one key and one daily budget for all of them. Every request is
 * counted (sports_api_usage, per UTC day, sport and endpoint) and refused once the day's budget
 * is spent — callers cache what they get. The key is read from config, never logged or returned.
 */
class ApiSportsClient
{
    public function configured(): bool
    {
        return filled(config('services.api_sports.key'));
    }

    /** The day's budget: the admin's override, else what the provider reported, else .env. */
    public function dailyLimit(): int
    {
        return (int) (SportsSetting::current()->daily_limit
            ?: Cache::get('sports.provider_limit')
            ?: config('services.api_sports.daily_limit', 100));
    }

    public function usedToday(?string $sport = null): int
    {
        return (int) DB::table('sports_api_usage')->where('date', now()->utc()->toDateString())
            ->when($sport !== null, fn ($q) => $q->where('sport_key', $sport))->sum('requests');
    }

    public function leftToday(): int
    {
        return max(0, $this->dailyLimit() - $this->usedToday());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{response: mixed, errors: mixed, results: int}
     */
    public function get(string $sport, string $endpoint, array $query = []): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('API_SPORTS_KEY is not set.');
        }
        if ($this->leftToday() <= 0) {
            throw new RuntimeException('The API-Sports daily request budget is used up.');
        }
        $url = (string) config("sports.sports.{$sport}.url");
        if ($url === '') {
            throw new RuntimeException("Unknown sport: {$sport}.");
        }

        $this->count($sport, $endpoint);

        $response = Http::baseUrl($url)
            ->withHeaders(['x-apisports-key' => (string) config('services.api_sports.key')])
            ->acceptJson()
            ->timeout((int) config('services.api_sports.timeout', 15))
            ->retry(2, 500, throw: false)
            ->get('/'.ltrim($endpoint, '/'), $query);

        $json = (array) $response->json();
        $errors = $json['errors'] ?? ($response->successful() ? [] : ['http' => $response->status()]);

        // The provider tells us the real limit with every answer (x-ratelimit-requests-limit).
        if (($limit = (int) $response->header('x-ratelimit-requests-limit')) > 0) {
            Cache::put('sports.provider_limit', $limit, now()->addDay());
        }

        return [
            'response' => $json['response'] ?? null,
            // API-Sports answers 200 with the problem in `errors` (bad key, plan limits…).
            'errors' => $errors,
            'results' => (int) ($json['results'] ?? 0),
        ];
    }

    /**
     * Like get(), but a provider problem becomes an exception (for sync jobs).
     *
     * @param  array<string, mixed>  $query
     * @return list<mixed>
     */
    public function list(string $sport, string $endpoint, array $query = []): array
    {
        $r = $this->get($sport, $endpoint, $query);
        if (! empty($r['errors'])) {
            throw new RuntimeException("API-Sports {$sport}/{$endpoint}: ".json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
        }

        return array_values((array) ($r['response'] ?? []));
    }

    private function count(string $sport, string $endpoint): void
    {
        $keys = ['date' => now()->utc()->toDateString(), 'sport_key' => $sport, 'endpoint' => substr($endpoint, 0, 40)];
        $row = DB::table('sports_api_usage')->where($keys);
        if ($row->increment('requests') > 0) {
            return;
        }
        try {
            DB::table('sports_api_usage')->insert($keys + ['requests' => 1]);
        } catch (QueryException) {
            DB::table('sports_api_usage')->where($keys)->increment('requests');
        }
    }
}
