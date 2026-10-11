<?php

namespace Modules\Sports\Data;

use Illuminate\Support\Str;
use Modules\Sports\Support\ApiSportsClient;

/**
 * The API-Sports "games" APIs (v1: basketball, volleyball, handball, hockey, rugby, baseball):
 * games by day with a score per period. No event feed or line-ups — the live loop asks for the
 * day's games (one request covers every league) and reads the scores.
 */
class GamesAdapter implements SportAdapter
{
    public function __construct(private readonly ApiSportsClient $client, private readonly string $sport) {}

    public function competitions(): array
    {
        $today = now()->toDateString();

        return array_values(array_filter(array_map(function ($row) use ($today) {
            $seasons = collect((array) ($row['seasons'] ?? []));
            $season = $seasons->first(fn ($s) => ($s['current'] ?? false) === true)
                ?? $seasons->first(fn ($s) => ($s['start'] ?? '') <= $today && ($s['end'] ?? '') >= $today)
                ?? $seasons->sortBy('end')->last();
            if (! isset($row['id']) || $season === null) {
                return null;
            }

            return [
                'provider_id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'type' => strtolower((string) ($row['type'] ?? '')) ?: null,
                'logo' => $row['logo'] ?? null,
                'country_name' => data_get($row, 'country.name'),
                'country_code' => data_get($row, 'country.code'),
                'flag' => data_get($row, 'country.flag'),
                'season' => isset($season['season']) ? (string) $season['season'] : null,
                'season_start' => $season['start'] ?? null,
                'season_end' => $season['end'] ?? null,
                'coverage' => ['events' => false, 'lineups' => false, 'statistics' => false, 'standings' => (bool) data_get($season, 'coverage.standings', true), 'top_scorers' => false],
            ];
        }, $this->client->list($this->sport, 'leagues'))));
    }

    public function schedule(string $date): array
    {
        return array_map(fn ($g) => $this->game($g), $this->client->list($this->sport, 'games', ['date' => $date, 'timezone' => 'UTC']));
    }

    public function live(array $competitionIds, array $dates): array
    {
        $wanted = array_flip(array_map('intval', $competitionIds));
        $out = [];
        foreach (array_values(array_unique($dates)) as $date) {
            foreach ($this->schedule($date) as $g) {
                if (isset($wanted[$g['competition']['provider_id']])) {
                    $out[] = $g;
                }
            }
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
        $rows = [];
        foreach ($this->client->list($this->sport, 'standings', ['league' => $competitionId, 'season' => $season]) as $group) {
            foreach ((array) $group as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $win = (int) (data_get($r, 'games.win.total') ?? data_get($r, 'games.win', 0));
                $lose = (int) (data_get($r, 'games.lose.total') ?? data_get($r, 'games.lose', 0));
                $draw = (int) (data_get($r, 'games.draw.total') ?? data_get($r, 'games.draw', 0));
                $for = (int) (data_get($r, 'points.for') ?? data_get($r, 'goals.for', 0));
                $against = (int) (data_get($r, 'points.against') ?? data_get($r, 'goals.against', 0));
                $rows[] = [
                    'group' => (string) (data_get($r, 'group.name') ?? $r['stage'] ?? ''),
                    'rank' => (int) ($r['position'] ?? 0),
                    'team' => ['provider_id' => (int) data_get($r, 'team.id'), 'name' => (string) data_get($r, 'team.name', ''), 'logo' => data_get($r, 'team.logo'), 'code' => null, 'national' => false],
                    'points' => is_numeric($r['points'] ?? null) ? (int) $r['points'] : $win * 2 + $draw,
                    'played' => (int) (data_get($r, 'games.played') ?? $win + $lose + $draw),
                    'win' => $win,
                    'draw' => $draw,
                    'lose' => $lose,
                    'goals_for' => $for,
                    'goals_against' => $against,
                    'goal_diff' => $for - $against,
                    'form' => $r['form'] ?? null,
                    'description' => $r['description'] ?? null,
                    'updated_at' => null,
                ];
            }
        }

        return $rows;
    }

    public function topScorers(int $competitionId, string $season): array
    {
        return [];
    }

    // ------------------------------------------------------------------ mapping

    /**
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    public function game(array $g): array
    {
        $code = (string) data_get($g, 'status.short', 'NS');
        $status = self::status($code);
        [$home, $away, $periods] = $this->scores($g);
        $winner = $status === 'finished' && $home !== null && $away !== null ? ($home > $away ? 'home' : ($home < $away ? 'away' : 'draw')) : null;
        $timer = data_get($g, 'status.timer');

        return [
            'provider_id' => (int) ($g['id'] ?? 0),
            'competition' => [
                'provider_id' => (int) data_get($g, 'league.id'),
                'name' => data_get($g, 'league.name'),
                'country' => data_get($g, 'country.name'),
                'logo' => data_get($g, 'league.logo'),
                'flag' => data_get($g, 'country.flag'),
                'season' => data_get($g, 'league.season') !== null ? (string) data_get($g, 'league.season') : null,
                'round' => $g['week'] ?? $g['stage'] ?? $g['round'] ?? null,
            ],
            'home' => $this->team((array) data_get($g, 'teams.home', [])),
            'away' => $this->team((array) data_get($g, 'teams.away', [])),
            'starts_at' => $g['date'] ?? null,
            'status' => $status,
            'status_code' => $code,
            'status_label' => data_get($g, 'status.long'),
            'elapsed' => is_numeric($timer) ? (int) $timer : null,
            'elapsed_extra' => null,
            'home_score' => $home,
            'away_score' => $away,
            'scores' => ['periods' => $periods],
            'winner' => $winner,
            'venue' => is_string($g['venue'] ?? null) ? $g['venue'] : data_get($g, 'venue.name'),
            'city' => data_get($g, 'venue.city'),
            'referee' => null,
            'events' => null,
            'statistics' => null,
            'lineups' => null,
        ];
    }

    public static function status(string $code): string
    {
        return match (true) {
            in_array($code, ['NS', 'TBD'], true) => 'scheduled',
            in_array($code, ['FT', 'AOT', 'AET', 'AP', 'AW', 'AWD', 'WO', 'END'], true) => 'finished',
            in_array($code, ['POST', 'PST'], true) => 'postponed',
            in_array($code, ['CANC', 'ABD'], true) => 'cancelled',
            in_array($code, ['SUSP', 'INTR', 'INT'], true) => 'suspended',
            in_array($code, ['HT', 'BT', 'PT'], true) => 'break',
            default => 'live',
        };
    }

    /**
     * Totals plus one entry per period, whatever the sport calls them.
     *
     * @param  array<string, mixed>  $g
     * @return array{0: ?int, 1: ?int, 2: list<array{label: string, home: mixed, away: mixed}>}
     */
    private function scores(array $g): array
    {
        $h = data_get($g, 'scores.home');
        $a = data_get($g, 'scores.away');
        $periods = [];

        if (is_array($h)) {
            // basketball (quarter_1…, over_time, total) · baseball (innings{1…}, hits, errors, total)
            foreach ($h as $k => $v) {
                if (in_array($k, ['total', 'hits', 'errors'], true)) {
                    continue;
                }
                if ($k === 'innings' && is_array($v)) {
                    foreach ($v as $inning => $runs) {
                        $periods[] = ['label' => (string) $inning, 'home' => $runs, 'away' => data_get($a, "innings.{$inning}")];
                    }

                    continue;
                }
                if ($v !== null) {
                    $periods[] = ['label' => strtoupper(str_replace(['quarter_', 'over_time'], ['Q', 'OT'], (string) $k)), 'home' => $v, 'away' => is_array($a) ? ($a[$k] ?? null) : null];
                }
            }
            $home = isset($h['total']) ? (int) $h['total'] : null;
            $away = isset($a['total']) ? (int) $a['total'] : null;
        } else {
            // volleyball (sets), handball, hockey, rugby: totals + `periods{first, second…}`
            $home = $h !== null ? (int) $h : null;
            $away = $a !== null ? (int) $a : null;
            foreach ((array) ($g['periods'] ?? []) as $k => $v) {
                if (is_array($v) && (($v['home'] ?? null) !== null || ($v['away'] ?? null) !== null)) {
                    $periods[] = ['label' => Str::upper(Str::substr((string) $k, 0, 3)), 'home' => $v['home'] ?? null, 'away' => $v['away'] ?? null];
                }
            }
        }

        return [$home, $away, $periods];
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function team(array $t): array
    {
        return ['provider_id' => (int) ($t['id'] ?? 0), 'name' => (string) ($t['name'] ?? ''), 'logo' => $t['logo'] ?? null, 'code' => null, 'national' => false];
    }
}
