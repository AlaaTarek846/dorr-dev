<?php

namespace Modules\Sports\Data;

use Modules\Sports\Support\ApiSportsClient;

/**
 * API-MMA (v1). A fight: the two fighters as home and away, the event as its round, the weight
 * class and — once it's over — how it was won (KO/TKO, submission, decision), in which round and
 * when, in `scores.fight`. The results come from one request for the whole day.
 * Built from the provider's documented shape (the free plan had no fights to try on 2026-10-07).
 */
class FightsAdapter implements SportAdapter
{
    public const CIRCUIT = 1;

    public function __construct(private readonly ApiSportsClient $client, private readonly string $sport = 'mma') {}

    public function competitions(): array
    {
        $year = (string) now()->year;

        return [[
            'provider_id' => self::CIRCUIT, 'name' => 'MMA', 'type' => 'cup', 'logo' => null, 'country_name' => 'World', 'country_code' => null, 'flag' => null,
            'season' => $year, 'season_start' => null, 'season_end' => null,
            'coverage' => ['events' => false, 'lineups' => false, 'statistics' => false, 'standings' => false, 'top_scorers' => false],
        ]];
    }

    public function schedule(string $date): array
    {
        $fights = $this->client->list($this->sport, 'fights', ['date' => $date, 'timezone' => 'UTC']);
        $results = [];
        // One more request only when the day has finished fights.
        if (collect($fights)->contains(fn ($f) => self::status((string) data_get($f, 'status.short')) === 'finished')) {
            foreach ($this->client->list($this->sport, 'fights/results', ['date' => $date]) as $r) {
                $results[(int) data_get($r, 'fight.id')] = $r;
            }
        }

        return array_values(array_map(fn ($f) => $this->fight($f, $results[(int) ($f['id'] ?? 0)] ?? null), $fights));
    }

    public function live(array $competitionIds, array $dates): array
    {
        $out = [];
        foreach (array_values(array_unique($dates)) as $date) {
            array_push($out, ...$this->schedule($date));
        }

        return $out;
    }

    public function hasDetails(): bool
    {
        return false;
    }

    public function details(array $matchIds): array
    {
        return [];
    }

    public function standings(int $competitionId, string $season): array
    {
        return [];
    }

    public function topScorers(int $competitionId, string $season): array
    {
        return [];
    }

    public static function status(string $code): string
    {
        return match ($code) {
            'NS' => 'scheduled',
            'IN', 'LIVE' => 'live',
            'EOR' => 'break',
            'FT', 'PF', 'WO' => 'finished',
            'PST' => 'postponed',
            'CANC' => 'cancelled',
            default => 'scheduled',
        };
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    public function fight(array $f, ?array $result): array
    {
        $code = (string) data_get($f, 'status.short', 'NS');
        $status = self::status($code);
        $fighter = fn (string $k) => ['provider_id' => (int) data_get($f, "fighters.{$k}.id"), 'name' => (string) data_get($f, "fighters.{$k}.name", ''), 'logo' => data_get($f, "fighters.{$k}.logo"), 'code' => null, 'national' => false];
        $winner = match (true) {
            data_get($f, 'fighters.first.winner') === true => 'home',
            data_get($f, 'fighters.second.winner') === true => 'away',
            $status === 'finished' && $result !== null && str_contains(strtolower((string) ($result['won_type'] ?? '')), 'draw') => 'draw',
            default => null,
        };

        return [
            'provider_id' => (int) ($f['id'] ?? 0),
            'competition' => ['provider_id' => self::CIRCUIT, 'name' => 'MMA', 'country' => 'World', 'logo' => null, 'flag' => null, 'season' => null, 'round' => $f['slug'] ?? null],
            'home' => $fighter('first'),
            'away' => $fighter('second'),
            'starts_at' => $f['date'] ?? null,
            'status' => $status,
            'status_code' => $code,
            'status_label' => data_get($f, 'status.long'),
            'elapsed' => null,
            'elapsed_extra' => null,
            'home_score' => null,
            'away_score' => null,
            'scores' => [
                'periods' => [],
                'fight' => [
                    'category' => $f['category'] ?? null, 'main' => (bool) ($f['is_main'] ?? false),
                    'won_by' => $result['won_type'] ?? null, 'round' => $result['round'] ?? null, 'time' => $result['minute'] ?? null,
                ],
            ],
            'winner' => $winner,
            'venue' => null,
            'city' => null,
            'referee' => null,
            'events' => null,
            'statistics' => null,
            'lineups' => null,
        ];
    }
}
