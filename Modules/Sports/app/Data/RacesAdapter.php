<?php

namespace Modules\Sports\Data;

use Modules\Sports\Support\ApiSportsClient;

/**
 * API-Formula-1 (v1). A race has no home and away: it's one "match" of the championship with the
 * Grand Prix as its round, the circuit as its venue, laps as its minute, and the classification
 * (position, driver, team, time, gap, pits) in `scores.results`. Races and sprints only.
 * The championship is one competition; its standings are the drivers' ranking.
 */
class RacesAdapter implements SportAdapter
{
    public const CHAMPIONSHIP = 1;

    private const TYPES = ['Race', 'Sprint'];

    public function __construct(private readonly ApiSportsClient $client, private readonly string $sport = 'formula1') {}

    public function competitions(): array
    {
        $year = (string) now()->year;

        return [[
            'provider_id' => self::CHAMPIONSHIP, 'name' => 'Formula 1 World Championship', 'type' => 'league', 'logo' => null,
            'country_name' => 'World', 'country_code' => null, 'flag' => null, 'season' => $year, 'season_start' => $year.'-01-01', 'season_end' => $year.'-12-31',
            'coverage' => ['events' => false, 'lineups' => false, 'statistics' => false, 'standings' => true, 'top_scorers' => false],
        ]];
    }

    public function schedule(string $date): array
    {
        return array_values(array_map(fn ($r) => $this->race($r), array_filter(
            $this->client->list($this->sport, 'races', ['date' => $date, 'timezone' => 'UTC']),
            fn ($r) => in_array($r['type'] ?? '', self::TYPES, true),
        )));
    }

    /** The day's races; a running one also gets its live classification (one more request each). */
    public function live(array $competitionIds, array $dates): array
    {
        $out = [];
        foreach (array_values(array_unique($dates)) as $date) {
            foreach ($this->schedule($date) as $race) {
                if ($race['status'] === 'live') {
                    $race['scores']['results'] = $this->results((int) $race['provider_id']);
                }
                $out[] = $race;
            }
        }

        return $out;
    }

    public function hasDetails(): bool
    {
        return true;
    }

    /** The final classification of races that just ended (one request per race). */
    public function details(array $matchIds): array
    {
        $out = [];
        foreach ($matchIds as $id) {
            $race = $this->client->list($this->sport, 'races', ['id' => $id, 'timezone' => 'UTC'])[0] ?? null;
            if ($race !== null) {
                $n = $this->race($race);
                $n['scores']['results'] = $this->results((int) $id);
                $out[] = $n;
            }
        }

        return $out;
    }

    public function standings(int $competitionId, string $season): array
    {
        return array_values(array_map(fn ($r) => [
            'group' => '',
            'rank' => (int) ($r['position'] ?? 0),
            'team' => ['provider_id' => (int) data_get($r, 'driver.id'), 'name' => (string) data_get($r, 'driver.name', ''), 'logo' => data_get($r, 'driver.image'), 'code' => data_get($r, 'driver.abbr'), 'national' => false],
            'points' => (int) ($r['points'] ?? 0),
            'played' => 0,
            'win' => (int) ($r['wins'] ?? 0),
            'draw' => 0,
            'lose' => 0,
            'goals_for' => 0,
            'goals_against' => 0,
            'goal_diff' => 0,
            'form' => null,
            'description' => data_get($r, 'team.name'),
            'updated_at' => null,
        ], $this->client->list($this->sport, 'rankings/drivers', ['season' => $season])));
    }

    public function topScorers(int $competitionId, string $season): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public function race(array $r): array
    {
        $status = match (strtolower((string) ($r['status'] ?? ''))) {
            'live' => 'live',
            'completed' => 'finished',
            'cancelled' => 'cancelled',
            'postponed' => 'postponed',
            default => 'scheduled',
        };

        return [
            'provider_id' => (int) ($r['id'] ?? 0),
            'competition' => ['provider_id' => self::CHAMPIONSHIP, 'name' => 'Formula 1', 'country' => 'World', 'logo' => null, 'flag' => null,
                'season' => isset($r['season']) ? (string) $r['season'] : null, 'round' => data_get($r, 'competition.name')],
            'home' => [],
            'away' => [],
            'starts_at' => $r['date'] ?? null,
            'status' => $status,
            'status_code' => (string) ($r['status'] ?? ''),
            'status_label' => $r['type'] ?? null,
            'elapsed' => data_get($r, 'laps.current'),
            'elapsed_extra' => null,
            'home_score' => null,
            'away_score' => null,
            'scores' => [
                'periods' => [],
                'race' => [
                    'type' => $r['type'] ?? null, 'laps' => data_get($r, 'laps.total'), 'lap' => data_get($r, 'laps.current'),
                    'circuit' => data_get($r, 'circuit.name'), 'circuit_image' => data_get($r, 'circuit.image'), 'country' => data_get($r, 'competition.location.country'),
                    'distance' => $r['distance'] ?? null, 'fastest_lap' => data_get($r, 'fastest_lap.time'),
                ],
            ],
            'winner' => null,
            'venue' => data_get($r, 'circuit.name'),
            'city' => data_get($r, 'competition.location.city'),
            'referee' => null,
            'events' => null,
            'statistics' => null,
            'lineups' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function results(int $raceId): array
    {
        return array_values(array_map(fn ($p) => [
            'position' => $p['position'] ?? null, 'driver' => data_get($p, 'driver.name'), 'abbr' => data_get($p, 'driver.abbr'), 'number' => data_get($p, 'driver.number'),
            'image' => data_get($p, 'driver.image'), 'team' => data_get($p, 'team.name'), 'team_logo' => data_get($p, 'team.logo'),
            'time' => $p['time'] ?? null, 'gap' => $p['gap'] ?? null, 'laps' => $p['laps'] ?? null, 'grid' => $p['grid'] ?? null, 'pits' => $p['pits'] ?? null,
        ], array_slice($this->client->list($this->sport, 'rankings/races', ['race' => $raceId]), 0, 22)));
    }
}
