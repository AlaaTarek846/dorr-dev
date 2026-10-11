<?php

namespace Modules\Sports\Data;

use Illuminate\Support\Facades\Cache;
use Modules\Sports\Support\ApiSportsClient;
use RuntimeException;

/** API-Football (v3): fixtures with events, statistics and line-ups; live for many leagues in one call. */
class FootballAdapter implements SportAdapter
{
    private const BATCH = 20;

    /** The plan refused `ids` today: details one match per request. */
    public const NO_IDS = 'sports:football:no-ids';

    public function __construct(private readonly ApiSportsClient $client, private readonly string $sport = 'football') {}

    public function competitions(): array
    {
        return array_values(array_filter(array_map(function ($row) {
            $season = collect((array) ($row['seasons'] ?? []))->firstWhere('current', true) ?? collect((array) ($row['seasons'] ?? []))->last();
            if (! isset($row['league']['id'])) {
                return null;
            }

            return [
                'provider_id' => (int) $row['league']['id'],
                'name' => (string) ($row['league']['name'] ?? ''),
                'type' => strtolower((string) ($row['league']['type'] ?? '')) ?: null,
                'logo' => $row['league']['logo'] ?? null,
                'country_name' => $row['country']['name'] ?? null,
                'country_code' => $row['country']['code'] ?? null,
                'flag' => $row['country']['flag'] ?? null,
                'season' => isset($season['year']) ? (string) $season['year'] : null,
                'season_start' => $season['start'] ?? null,
                'season_end' => $season['end'] ?? null,
                'coverage' => [
                    'events' => (bool) data_get($season, 'coverage.fixtures.events', true),
                    'lineups' => (bool) data_get($season, 'coverage.fixtures.lineups', true),
                    'statistics' => (bool) data_get($season, 'coverage.fixtures.statistics_fixtures', true),
                    'standings' => (bool) data_get($season, 'coverage.standings', true),
                    'top_scorers' => (bool) data_get($season, 'coverage.top_scorers', true),
                ],
            ];
        }, $this->client->list($this->sport, 'leagues', ['current' => 'true']))));
    }

    public function schedule(string $date): array
    {
        return array_map(fn ($f) => $this->fixture($f), $this->client->list($this->sport, 'fixtures', ['date' => $date, 'timezone' => 'UTC']));
    }

    public function live(array $competitionIds, array $dates): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($competitionIds)), self::BATCH) as $chunk) {
            foreach ($this->client->list($this->sport, 'fixtures', ['live' => implode('-', $chunk), 'timezone' => 'UTC']) as $f) {
                $out[] = $this->fixture($f);
            }
        }

        return $out;
    }

    public function hasDetails(): bool
    {
        return true;
    }

    public function details(array $matchIds): array
    {
        $ids = array_values(array_unique($matchIds));
        $out = [];
        if (count($ids) > 1 && ! Cache::has(self::NO_IDS)) {
            try {
                foreach (array_chunk($ids, self::BATCH) as $chunk) {
                    foreach ($this->client->list($this->sport, 'fixtures', ['ids' => implode('-', $chunk), 'timezone' => 'UTC']) as $f) {
                        $out[] = $this->fixture($f, true);
                    }
                }

                return $out;
            } catch (RuntimeException $e) {
                // "Free plans do not have access to the Ids parameter": one by one from now on.
                if (! str_contains($e->getMessage(), '"plan"')) {
                    throw $e;
                }
                Cache::put(self::NO_IDS, true, now()->addDay());
            }
        }
        foreach ($ids as $id) {
            foreach ($this->client->list($this->sport, 'fixtures', ['id' => $id, 'timezone' => 'UTC']) as $f) {
                $out[] = $this->fixture($f, true);
            }
        }

        return $out;
    }

    /** How many matches one details request carries on this plan. */
    public function detailsBatch(): int
    {
        return Cache::has(self::NO_IDS) ? 1 : self::BATCH;
    }

    /**
     * The whole season of a competition (one request), or of a team (all its competitions).
     *
     * @return list<array<string, mixed>>
     */
    public function season(string $season, ?int $competitionId = null, ?int $teamId = null): array
    {
        $query = array_filter(['league' => $competitionId, 'team' => $teamId, 'season' => $season, 'timezone' => 'UTC']);

        return array_map(fn ($f) => $this->fixture($f), $this->client->list($this->sport, 'fixtures', $query));
    }

    public function standings(int $competitionId, string $season): array
    {
        $groups = (array) data_get($this->client->list($this->sport, 'standings', ['league' => $competitionId, 'season' => $season]), '0.league.standings', []);
        $rows = [];
        foreach ($groups as $group) {
            foreach ((array) $group as $r) {
                $rows[] = [
                    'group' => (string) ($r['group'] ?? ''),
                    'rank' => (int) ($r['rank'] ?? 0),
                    'team' => $this->team($r['team'] ?? []),
                    'points' => (int) ($r['points'] ?? 0),
                    'played' => (int) data_get($r, 'all.played', 0),
                    'win' => (int) data_get($r, 'all.win', 0),
                    'draw' => (int) data_get($r, 'all.draw', 0),
                    'lose' => (int) data_get($r, 'all.lose', 0),
                    'goals_for' => (int) data_get($r, 'all.goals.for', 0),
                    'goals_against' => (int) data_get($r, 'all.goals.against', 0),
                    'goal_diff' => (int) ($r['goalsDiff'] ?? 0),
                    'home' => $this->split($r['home'] ?? null),
                    'away' => $this->split($r['away'] ?? null),
                    'form' => $r['form'] ?? null,
                    'description' => $r['description'] ?? null,
                    'updated_at' => $r['update'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $s
     * @return array<string, int>|null
     */
    private function split(?array $s): ?array
    {
        return $s === null ? null : [
            'played' => (int) ($s['played'] ?? 0), 'win' => (int) ($s['win'] ?? 0), 'draw' => (int) ($s['draw'] ?? 0), 'lose' => (int) ($s['lose'] ?? 0),
            'goals_for' => (int) data_get($s, 'goals.for', 0), 'goals_against' => (int) data_get($s, 'goals.against', 0),
        ];
    }

    public function topScorers(int $competitionId, string $season): array
    {
        return array_values(array_map(fn ($r, $i) => [
            'rank' => $i + 1,
            'player' => data_get($r, 'player.name'),
            'photo' => data_get($r, 'player.photo'),
            'team' => data_get($r, 'statistics.0.team.name'),
            'team_logo' => data_get($r, 'statistics.0.team.logo'),
            'goals' => (int) data_get($r, 'statistics.0.goals.total', 0),
            'assists' => (int) data_get($r, 'statistics.0.goals.assists', 0),
        ], $list = array_slice($this->client->list($this->sport, 'players/topscorers', ['league' => $competitionId, 'season' => $season]), 0, 20), array_keys($list)));
    }

    // ------------------------------------------------------------------ mapping

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function fixture(array $f, bool $full = false): array
    {
        $code = (string) data_get($f, 'fixture.status.short', 'NS');
        $status = self::status($code);
        $home = (int) data_get($f, 'teams.home.id');
        $homeGoals = data_get($f, 'goals.home');
        $awayGoals = data_get($f, 'goals.away');
        $winner = match (true) {
            data_get($f, 'teams.home.winner') === true => 'home',
            data_get($f, 'teams.away.winner') === true => 'away',
            $status === 'finished' && $homeGoals !== null && $homeGoals === $awayGoals => 'draw',
            default => null,
        };
        $periods = array_values(array_filter([
            ['label' => 'HT', 'home' => data_get($f, 'score.halftime.home'), 'away' => data_get($f, 'score.halftime.away')],
            ['label' => 'FT', 'home' => data_get($f, 'score.fulltime.home'), 'away' => data_get($f, 'score.fulltime.away')],
            ['label' => 'ET', 'home' => data_get($f, 'score.extratime.home'), 'away' => data_get($f, 'score.extratime.away')],
            ['label' => 'PEN', 'home' => data_get($f, 'score.penalty.home'), 'away' => data_get($f, 'score.penalty.away')],
        ], fn ($p) => $p['home'] !== null || $p['away'] !== null));

        $events = array_key_exists('events', $f) && is_array($f['events'])
            ? array_values(array_map(fn ($e, $i) => $this->event($e, $home, $i), $f['events'], array_keys($f['events'])))
            : null;

        return [
            'provider_id' => (int) data_get($f, 'fixture.id'),
            'competition' => [
                'provider_id' => (int) data_get($f, 'league.id'),
                'name' => data_get($f, 'league.name'),
                'country' => data_get($f, 'league.country'),
                'logo' => data_get($f, 'league.logo'),
                'flag' => data_get($f, 'league.flag'),
                'season' => data_get($f, 'league.season') !== null ? (string) data_get($f, 'league.season') : null,
                'round' => data_get($f, 'league.round'),
            ],
            'home' => $this->team((array) data_get($f, 'teams.home', [])),
            'away' => $this->team((array) data_get($f, 'teams.away', [])),
            'starts_at' => data_get($f, 'fixture.date'),
            'status' => $status,
            'status_code' => $code,
            'status_label' => data_get($f, 'fixture.status.long'),
            'elapsed' => data_get($f, 'fixture.status.elapsed'),
            'elapsed_extra' => data_get($f, 'fixture.status.extra'),
            'home_score' => $homeGoals,
            'away_score' => $awayGoals,
            'scores' => ['periods' => $periods],
            'winner' => $winner,
            'venue' => data_get($f, 'fixture.venue.name'),
            'venue_id' => data_get($f, 'fixture.venue.id'),
            'city' => data_get($f, 'fixture.venue.city'),
            'referee' => data_get($f, 'fixture.referee'),
            'events' => $events,
            'statistics' => $full ? $this->statistics((array) ($f['statistics'] ?? []), $home) : null,
            'lineups' => $full && ! empty($f['lineups']) ? $this->lineups((array) $f['lineups'], $home) : null,
            'players' => $full && ! empty($f['players']) ? $this->players((array) $f['players'], $home) : null,
        ];
    }

    public static function status(string $code): string
    {
        return match ($code) {
            'TBD', 'NS' => 'scheduled',
            '1H', '2H', 'ET', 'P', 'LIVE' => 'live',
            'HT', 'BT' => 'break',
            'FT', 'AET', 'PEN', 'AWD', 'WO' => 'finished',
            'PST' => 'postponed',
            'CANC', 'ABD' => 'cancelled',
            'SUSP', 'INT' => 'suspended',
            default => 'scheduled',
        };
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function team(array $t): array
    {
        return ['provider_id' => (int) ($t['id'] ?? 0), 'name' => (string) ($t['name'] ?? ''), 'logo' => $t['logo'] ?? null, 'code' => $t['code'] ?? null, 'national' => (bool) ($t['national'] ?? false)];
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function event(array $e, int $homeId, int $i): array
    {
        $type = strtolower((string) ($e['type'] ?? ''));
        $detail = (string) ($e['detail'] ?? '');
        $kind = match (true) {
            $type === 'goal' && $detail === 'Own Goal' => 'own_goal',
            $type === 'goal' && $detail === 'Penalty' => 'penalty',
            $type === 'goal' && $detail === 'Missed Penalty' => 'missed_penalty',
            $type === 'goal' => 'goal',
            $type === 'card' && str_contains(strtolower($detail), 'second') => 'second_yellow',
            $type === 'card' && str_contains(strtolower($detail), 'red') => 'red',
            $type === 'card' => 'yellow',
            $type === 'subst' => 'sub',
            $type === 'var' => 'var',
            default => 'other',
        };
        $minute = data_get($e, 'time.elapsed');
        $extra = data_get($e, 'time.extra');
        $side = (int) data_get($e, 'team.id') === $homeId ? 'home' : 'away';
        $player = data_get($e, 'player.name');

        return [
            'key' => substr(md5(implode('|', [$minute, $extra, $side, $kind, $player, data_get($e, 'assist.name')])), 0, 20),
            'minute' => $minute,
            'extra' => $extra,
            'side' => $side,
            'type' => $kind,
            'player' => $player,
            'player_id' => data_get($e, 'player.id'),
            'assist' => data_get($e, 'assist.name'),
            'assist_id' => data_get($e, 'assist.id'),
            'detail' => $detail ?: null,
            'sort' => $i,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stats
     * @return list<array{type: string, home: mixed, away: mixed}>|null
     */
    private function statistics(array $stats, int $homeId): ?array
    {
        if ($stats === []) {
            return null;
        }
        $bySide = [];
        foreach ($stats as $s) {
            $side = (int) data_get($s, 'team.id') === $homeId ? 'home' : 'away';
            foreach ((array) ($s['statistics'] ?? []) as $item) {
                $bySide[(string) $item['type']][$side] = $item['value'];
            }
        }

        return array_values(array_map(fn ($type, $v) => ['type' => $type, 'home' => $v['home'] ?? null, 'away' => $v['away'] ?? null], array_keys($bySide), $bySide));
    }

    /**
     * @param  list<array<string, mixed>>  $lineups
     * @return array<string, mixed>
     */
    private function lineups(array $lineups, int $homeId): array
    {
        $out = [];
        foreach ($lineups as $l) {
            $side = (int) data_get($l, 'team.id') === $homeId ? 'home' : 'away';
            $player = fn ($p) => [
                'id' => data_get($p, 'player.id'), 'name' => data_get($p, 'player.name'), 'number' => data_get($p, 'player.number'),
                'pos' => data_get($p, 'player.pos'), 'grid' => data_get($p, 'player.grid'),
            ];
            $out[$side] = [
                'formation' => $l['formation'] ?? null,
                'coach' => data_get($l, 'coach.name'),
                'coach_id' => data_get($l, 'coach.id'),
                'start' => array_map($player, (array) ($l['startXI'] ?? [])),
                'subs' => array_map($player, (array) ($l['substitutes'] ?? [])),
                'colors' => [
                    'primary' => ($c = data_get($l, 'team.colors.player.primary')) ? '#'.$c : null,
                    'number' => ($c = data_get($l, 'team.colors.player.number')) ? '#'.$c : null,
                    'border' => ($c = data_get($l, 'team.colors.player.border')) ? '#'.$c : null,
                ],
            ];
        }

        return $out;
    }

    /**
     * The players' match sheet: minutes, rating, captain, goals, cards… (from `fixtures?id(s)`).
     *
     * @param  list<array<string, mixed>>  $teams
     * @return array{home?: list<array<string, mixed>>, away?: list<array<string, mixed>>}
     */
    private function players(array $teams, int $homeId): array
    {
        $out = [];
        foreach ($teams as $t) {
            $side = (int) data_get($t, 'team.id') === $homeId ? 'home' : 'away';
            $out[$side] = array_values(array_map(function ($p) {
                $s = (array) data_get($p, 'statistics.0', []);

                return [
                    'id' => data_get($p, 'player.id'),
                    'name' => data_get($p, 'player.name'),
                    'number' => data_get($s, 'games.number'),
                    'pos' => data_get($s, 'games.position'),
                    'minutes' => data_get($s, 'games.minutes'),
                    'rating' => ($r = data_get($s, 'games.rating')) !== null ? (float) $r : null,
                    'captain' => (bool) data_get($s, 'games.captain', false),
                    'sub' => (bool) data_get($s, 'games.substitute', false),
                    'goals' => (int) data_get($s, 'goals.total', 0),
                    'assists' => (int) data_get($s, 'goals.assists', 0),
                    'saves' => data_get($s, 'goals.saves'),
                    'shots' => data_get($s, 'shots.total'),
                    'shots_on' => data_get($s, 'shots.on'),
                    'passes' => data_get($s, 'passes.total'),
                    'key_passes' => data_get($s, 'passes.key'),
                    'pass_accuracy' => data_get($s, 'passes.accuracy'),
                    'tackles' => data_get($s, 'tackles.total'),
                    'duels_won' => data_get($s, 'duels.won'),
                    'dribbles' => data_get($s, 'dribbles.success'),
                    'fouls' => data_get($s, 'fouls.committed'),
                    'yellow' => (int) data_get($s, 'cards.yellow', 0),
                    'red' => (int) data_get($s, 'cards.red', 0),
                ];
            }, (array) ($t['players'] ?? [])));
        }

        return $out;
    }
}
