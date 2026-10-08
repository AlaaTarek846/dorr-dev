<?php

namespace Modules\Sports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sports\Support\ApiSportsClient;
use Throwable;

/**
 * Everything the provider has besides the live scores (docs/sports-plan.md §10.2), kept in
 * `sports_cache` for as long as the provider's own "Recommended Calls" says it stays good: the
 * first person to open a squad, a player or a prediction pays one request, everyone after reads
 * it from here. A refusal of the plan ("Free plans do not have access…") is remembered for a week.
 *
 * read() answers {data, state, fetched_at}: state is `ok`, `pending` (not read yet — the budget
 * or a provider hiccup) or `unavailable` (the plan doesn't cover it).
 */
class SportsLibrary
{
    /** A plan refusal isn't asked again before this. */
    private const REFUSED_DAYS = 7;

    public function __construct(
        private readonly ApiSportsClient $client,
        private readonly SportsGovernor $governor,
    ) {}

    /**
     * @param  array<string, scalar>  $query
     * @param  int  $ttl  seconds the answer stays good
     * @return array{data: mixed, state: string, fetched_at: ?string}
     */
    public function read(string $sport, string $endpoint, array $query, int $ttl, bool $fetch = true): array
    {
        ksort($query);
        $hash = sha1(json_encode($query));
        $keys = ['sport_key' => $sport, 'endpoint' => $endpoint, 'params_hash' => $hash];
        $row = DB::table('sports_cache')->where($keys)->first();
        $now = CarbonImmutable::now('UTC');
        $fresh = $row !== null && $row->fetched_at !== null && $row->expires_at !== null && CarbonImmutable::parse($row->expires_at, 'UTC')->gt($now);
        $refused = $row !== null && $row->refused_until !== null && CarbonImmutable::parse($row->refused_until, 'UTC')->gt($now);

        if (! $fresh && ! $refused && $fetch && $this->governor->canSpend() && Cache::add('sports:lib:'.$sport.':'.$endpoint.':'.$hash, true, 60)) {
            $row = $this->fetch($sport, $endpoint, $query, $ttl, $keys, $row) ?? $row;
            $refused = $row !== null && $row->refused_until !== null && CarbonImmutable::parse($row->refused_until, 'UTC')->gt($now);
        }

        $data = $row?->payload !== null ? json_decode($row->payload, true) : null;

        return [
            'data' => $data,
            'state' => $row?->fetched_at !== null ? 'ok' : ($refused ? 'unavailable' : 'pending'),
            'fetched_at' => $row?->fetched_at !== null ? CarbonImmutable::parse($row->fetched_at, 'UTC')->toIso8601String() : null,
        ];
    }

    /** Whether this answer is stored and still good (no request). */
    public function fresh(string $sport, string $endpoint, array $query): bool
    {
        ksort($query);
        $row = DB::table('sports_cache')->where(['sport_key' => $sport, 'endpoint' => $endpoint, 'params_hash' => sha1(json_encode($query))])->first(['expires_at']);

        return $row?->expires_at !== null && CarbonImmutable::parse($row->expires_at, 'UTC')->isFuture();
    }

    /** Throw the stored answer away (e.g. a match finished: its prediction becomes history). */
    public function forget(string $sport, string $endpoint, array $query): void
    {
        ksort($query);
        DB::table('sports_cache')->where(['sport_key' => $sport, 'endpoint' => $endpoint, 'params_hash' => sha1(json_encode($query))])->update(['expires_at' => now()]);
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $keys
     */
    private function fetch(string $sport, string $endpoint, array $query, int $ttl, array $keys, ?object $old): ?object
    {
        try {
            $r = $this->client->get($sport, $endpoint, $query);
        } catch (Throwable $e) {
            Log::warning('[sports] library '.$endpoint.': '.$e->getMessage());

            return null;
        }
        $errors = (array) ($r['errors'] ?? []);
        $values = ['params' => json_encode($query), 'updated_at' => now()];
        if (isset($errors['plan'])) {
            $values += ['refused_until' => now()->addDays(self::REFUSED_DAYS), 'last_error' => mb_substr((string) $errors['plan'], 0, 250)];
        } elseif ($errors !== []) {
            // Something else (a bad parameter, a timeout): try again in a while, keep what we had.
            $values += ['expires_at' => now()->addMinutes(30), 'last_error' => mb_substr(json_encode($errors, JSON_UNESCAPED_UNICODE), 0, 250)];
        } else {
            $values += [
                'payload' => json_encode($r['response'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'results' => (int) ($r['results'] ?? 0),
                'fetched_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
                'refused_until' => null,
                'last_error' => null,
            ];
        }
        if ($old === null) {
            DB::table('sports_cache')->insertOrIgnore($keys + $values + ['created_at' => now()]);
        } else {
            DB::table('sports_cache')->where('id', $old->id)->update($values);
        }

        return DB::table('sports_cache')->where($keys)->first();
    }
}
