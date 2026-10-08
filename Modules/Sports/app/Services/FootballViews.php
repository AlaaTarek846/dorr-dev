<?php

namespace Modules\Sports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Models\SportsTeam;
use Modules\Sports\Support\SportsMedia;

/**
 * API-Football answers (kept raw in sports_cache) → what the app shows. Mapping on the way out
 * means a better screen never needs the provider again. Every team the provider names becomes
 * one of ours (so it opens), every photo goes through our copy (SportsMedia).
 */
class FootballViews
{
    /** @var array<int, SportsTeam> provider id → ours */
    private array $teams = [];

    public function __construct(private readonly SportsPresenter $presenter) {}

    /**
     * Find (or add) every team an answer mentions, in one query — before mapping it.
     */
    public function prime(mixed $raw): static
    {
        $found = [];
        $walk = function ($node) use (&$walk, &$found) {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['id'], $node['name']) && is_string($node['logo'] ?? null) && preg_match('#/teams/(\d+)\.png#', $node['logo'])) {
                $found[(int) $node['id']] = ['name' => (string) $node['name'], 'logo' => $node['logo']];
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($raw);
        $missing = array_diff_key($found, $this->teams);
        if ($missing === []) {
            return $this;
        }
        $sportId = (int) SportsSport::query()->where('key', 'football')->value('id');
        $known = SportsTeam::query()->with('translations')->where('sport_id', $sportId)->whereIn('provider_id', array_keys($missing))->get()->keyBy('provider_id');
        foreach ($missing as $pid => $t) {
            $this->teams[$pid] = $known->get($pid) ?? SportsTeam::query()->create(['sport_id' => $sportId, 'provider_id' => $pid, 'name' => $t['name'], 'logo' => $t['logo']]);
        }

        return $this;
    }

    /** @param  array<string, mixed>|null  $t  the provider's {id, name, logo} */
    public function team(?array $t): ?array
    {
        if (empty($t['id'])) {
            return null;
        }
        $ours = $this->teams[(int) $t['id']] ?? null;

        return $ours !== null ? $this->presenter->team($ours) : ['id' => null, 'name' => $t['name'] ?? null, 'logo' => SportsMedia::url($t['logo'] ?? null), 'code' => null, 'national' => false, 'color' => null];
    }

    /** @param  array<string, mixed>|null  $p  {id, name, photo?} */
    public function person(?array $p, string $kind = 'players'): ?array
    {
        if (empty($p['id']) && empty($p['name'])) {
            return null;
        }

        return ['id' => $p['id'] ?? null, 'name' => $p['name'] ?? null, 'photo' => SportsMedia::url($p['photo'] ?? null) ?? SportsMedia::of($kind, $p['id'] ?? null)];
    }

    // ------------------------------------------------------------------ teams

    /** `teams?id` → founded, venue (with its photo). */
    public function profile(array $raw): array
    {
        $r = (array) ($raw[0] ?? []);
        $v = (array) ($r['venue'] ?? []);

        return [
            'founded' => data_get($r, 'team.founded'),
            'country' => data_get($r, 'team.country'),
            'code' => data_get($r, 'team.code'),
            'venue' => empty($v['name']) ? null : [
                'id' => $v['id'] ?? null, 'name' => $v['name'], 'address' => $v['address'] ?? null, 'city' => $v['city'] ?? null,
                'capacity' => $v['capacity'] ?? null, 'surface' => $v['surface'] ?? null, 'image' => SportsMedia::url($v['image'] ?? null) ?? SportsMedia::of('venues', $v['id'] ?? null),
            ],
        ];
    }

    /** `coachs?team` (or `?id`) → the one in charge now, with his career. */
    public function coach(array $raw, ?int $teamProviderId = null): ?array
    {
        $list = collect($raw);
        $c = $teamProviderId === null ? $list->first() : ($list->first(fn ($c) => collect($c['career'] ?? [])->contains(fn ($j) => (int) data_get($j, 'team.id') === $teamProviderId && empty($j['end'])))
            ?? $list->first(fn ($c) => (int) data_get($c, 'team.id') === $teamProviderId) ?? $list->first());
        if ($c === null) {
            return null;
        }
        $this->prime($c);

        return [
            'id' => $c['id'] ?? null,
            'name' => $c['name'] ?? null,
            'firstname' => $c['firstname'] ?? null,
            'lastname' => $c['lastname'] ?? null,
            'age' => $c['age'] ?? null,
            'nationality' => $c['nationality'] ?? null,
            'birth' => $c['birth'] ?? null,
            'photo' => SportsMedia::url($c['photo'] ?? null) ?? SportsMedia::of('coachs', $c['id'] ?? null),
            'team' => $this->team($c['team'] ?? null),
            'career' => array_values(array_map(fn ($j) => ['team' => $this->team($j['team'] ?? null), 'start' => $j['start'] ?? null, 'end' => $j['end'] ?? null], (array) ($c['career'] ?? []))),
        ];
    }

    /** `players/squads?team` → by line, goalkeepers first. */
    public function squad(array $raw): array
    {
        $order = ['Goalkeeper' => 0, 'Defender' => 1, 'Midfielder' => 2, 'Attacker' => 3];
        $players = collect((array) data_get($raw, '0.players', []))->map(fn ($p) => [
            'id' => $p['id'] ?? null, 'name' => $p['name'] ?? null, 'age' => $p['age'] ?? null, 'number' => $p['number'] ?? null,
            'position' => $p['position'] ?? null, 'photo' => SportsMedia::url($p['photo'] ?? null) ?? SportsMedia::of('players', $p['id'] ?? null),
        ]);

        return $players->groupBy('position')->sortBy(fn ($g, $pos) => $order[$pos] ?? 9)
            ->map(fn (Collection $g, $pos) => ['position' => $pos, 'players' => $g->sortBy(fn ($p) => $p['number'] ?? 999)->values()->all()])->values()->all();
    }

    /** `teams/statistics` → the numbers the team page draws. */
    public function teamStats(array $raw): ?array
    {
        if ($raw === [] || ! isset($raw['fixtures'])) {
            return null;
        }
        $minutes = fn ($m) => array_values(array_map(fn ($k, $v) => ['range' => $k, 'total' => (int) ($v['total'] ?? 0), 'percent' => $v['percentage'] ?? null], array_keys((array) $m), (array) $m));

        return [
            'competition' => ['name' => data_get($raw, 'league.name'), 'logo' => SportsMedia::url(data_get($raw, 'league.logo')), 'season' => data_get($raw, 'league.season')],
            'form' => $raw['form'] ?? null,
            'fixtures' => $raw['fixtures'] ?? null,
            'goals' => [
                'for' => ['total' => data_get($raw, 'goals.for.total'), 'average' => data_get($raw, 'goals.for.average'), 'minute' => $minutes(data_get($raw, 'goals.for.minute', [])), 'under_over' => data_get($raw, 'goals.for.under_over')],
                'against' => ['total' => data_get($raw, 'goals.against.total'), 'average' => data_get($raw, 'goals.against.average'), 'minute' => $minutes(data_get($raw, 'goals.against.minute', [])), 'under_over' => data_get($raw, 'goals.against.under_over')],
            ],
            'biggest' => $raw['biggest'] ?? null,
            'clean_sheet' => $raw['clean_sheet'] ?? null,
            'failed_to_score' => $raw['failed_to_score'] ?? null,
            'penalty' => $raw['penalty'] ?? null,
            'lineups' => array_values((array) ($raw['lineups'] ?? [])),
            'cards' => ['yellow' => $minutes(data_get($raw, 'cards.yellow', [])), 'red' => $minutes(data_get($raw, 'cards.red', []))],
        ];
    }

    /**
     * `transfers?team` (or `?player`) → newest first: who came, who left, from or to where.
     *
     * @return list<array<string, mixed>>
     */
    public function transfers(array $raw, ?int $teamProviderId = null, int $limit = 60): array
    {
        $this->prime($raw);
        $rows = [];
        foreach ($raw as $entry) {
            foreach ((array) ($entry['transfers'] ?? []) as $t) {
                $in = (int) data_get($t, 'teams.in.id');
                $rows[] = [
                    'date' => $t['date'] ?? null,
                    'type' => $t['type'] ?? null,
                    'player' => $this->person($entry['player'] ?? null),
                    'direction' => $teamProviderId === null ? null : ($in === $teamProviderId ? 'in' : 'out'),
                    'from' => $this->team(data_get($t, 'teams.out')),
                    'to' => $this->team(data_get($t, 'teams.in')),
                ];
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

        return array_slice($rows, 0, $limit);
    }

    // ------------------------------------------------------------------ players

    /**
     * `players/profiles?player` (+ `players?id&season` for the season) → one player.
     *
     * @return array<string, mixed>|null
     */
    public function player(array $profiles, ?array $season): ?array
    {
        $p = (array) (data_get($season, '0.player') ?? data_get($profiles, '0.player') ?? []);
        if ($p === []) {
            return null;
        }
        $stats = (array) data_get($season, '0.statistics', []);
        $this->prime($stats);

        return [
            'id' => $p['id'] ?? null,
            'name' => $p['name'] ?? null,
            'firstname' => $p['firstname'] ?? null,
            'lastname' => $p['lastname'] ?? null,
            'age' => $p['age'] ?? null,
            'birth' => $p['birth'] ?? null,
            'nationality' => $p['nationality'] ?? null,
            'height' => $p['height'] ?? null,
            'weight' => $p['weight'] ?? null,
            'number' => $p['number'] ?? data_get($stats, '0.games.number'),
            'position' => $p['position'] ?? data_get($stats, '0.games.position'),
            'injured' => (bool) ($p['injured'] ?? false),
            'photo' => SportsMedia::url($p['photo'] ?? null) ?? SportsMedia::of('players', $p['id'] ?? null),
            'team' => $this->team(data_get($stats, '0.team')),
            'seasons' => array_values(array_map(fn ($s) => [
                'team' => $this->team($s['team'] ?? null),
                'competition' => ['name' => data_get($s, 'league.name'), 'logo' => SportsMedia::url(data_get($s, 'league.logo')), 'country' => data_get($s, 'league.country'), 'season' => data_get($s, 'league.season')],
                'appearances' => data_get($s, 'games.appearences'),
                'lineups' => data_get($s, 'games.lineups'),
                'minutes' => data_get($s, 'games.minutes'),
                'rating' => ($r = data_get($s, 'games.rating')) !== null ? round((float) $r, 2) : null,
                'captain' => (bool) data_get($s, 'games.captain'),
                'goals' => data_get($s, 'goals.total'),
                'assists' => data_get($s, 'goals.assists'),
                'saves' => data_get($s, 'goals.saves'),
                'conceded' => data_get($s, 'goals.conceded'),
                'shots' => data_get($s, 'shots.total'),
                'shots_on' => data_get($s, 'shots.on'),
                'passes' => data_get($s, 'passes.total'),
                'key_passes' => data_get($s, 'passes.key'),
                'pass_accuracy' => data_get($s, 'passes.accuracy'),
                'tackles' => data_get($s, 'tackles.total'),
                'interceptions' => data_get($s, 'tackles.interceptions'),
                'duels_won' => data_get($s, 'duels.won'),
                'duels' => data_get($s, 'duels.total'),
                'dribbles' => data_get($s, 'dribbles.success'),
                'dribbles_tried' => data_get($s, 'dribbles.attempts'),
                'fouls_drawn' => data_get($s, 'fouls.drawn'),
                'fouls' => data_get($s, 'fouls.committed'),
                'yellow' => data_get($s, 'cards.yellow'),
                'red' => (int) data_get($s, 'cards.red', 0) + (int) data_get($s, 'cards.yellowred', 0),
                'penalties_scored' => data_get($s, 'penalty.scored'),
                'penalties_missed' => data_get($s, 'penalty.missed'),
            ], array_filter($stats, fn ($s) => (int) data_get($s, 'games.appearences', 0) > 0 || (int) data_get($s, 'games.minutes', 0) > 0))),
        ];
    }

    /** `players/teams` → clubs and seasons. */
    public function career(array $raw): array
    {
        $this->prime($raw);

        return array_values(array_map(fn ($r) => ['team' => $this->team($r['team'] ?? null), 'seasons' => array_values((array) ($r['seasons'] ?? []))], $raw));
    }

    /** `trophies` → newest first. */
    public function trophies(array $raw): array
    {
        $rows = array_values(array_map(fn ($t) => ['competition' => $t['league'] ?? null, 'country' => $t['country'] ?? null, 'season' => $t['season'] ?? null, 'place' => $t['place'] ?? null], $raw));
        usort($rows, fn ($a, $b) => strcmp((string) $b['season'], (string) $a['season']));

        return $rows;
    }

    /** `sidelined` → injuries and suspensions, newest first. */
    public function sidelined(array $raw): array
    {
        $rows = array_values(array_map(fn ($s) => ['type' => $s['type'] ?? null, 'start' => $s['start'] ?? null, 'end' => $s['end'] ?? null], $raw));
        usort($rows, fn ($a, $b) => strcmp((string) $b['start'], (string) $a['start']));

        return $rows;
    }

    // ------------------------------------------------------------------ competitions

    /**
     * `players/top…` → the 20 best of a kind.
     *
     * @return list<array<string, mixed>>
     */
    public function leaders(array $raw, string $type): array
    {
        $this->prime($raw);

        return array_values(array_map(function ($r, $i) use ($type) {
            $s = (array) data_get($r, 'statistics.0', []);
            $value = match ($type) {
                'assists' => data_get($s, 'goals.assists'),
                'yellow' => data_get($s, 'cards.yellow'),
                'red' => (int) data_get($s, 'cards.red', 0) + (int) data_get($s, 'cards.yellowred', 0),
                default => data_get($s, 'goals.total'),
            };

            return [
                'rank' => $i + 1,
                'player' => $this->person($r['player'] ?? null) + ['nationality' => data_get($r, 'player.nationality'), 'age' => data_get($r, 'player.age')],
                'team' => $this->team($s['team'] ?? null),
                'value' => (int) $value,
                'goals' => (int) data_get($s, 'goals.total', 0),
                'assists' => (int) data_get($s, 'goals.assists', 0),
                'penalties' => (int) data_get($s, 'penalty.scored', 0),
                'appearances' => data_get($s, 'games.appearences'),
                'minutes' => data_get($s, 'games.minutes'),
                'rating' => ($x = data_get($s, 'games.rating')) !== null ? round((float) $x, 2) : null,
            ];
        }, $raw, array_keys($raw)));
    }

    /** `fixtures/rounds?dates=true` → [{name, dates}]. */
    public function rounds(array $raw): array
    {
        return array_values(array_map(fn ($r) => is_array($r) ? ['name' => $r['round'] ?? null, 'dates' => array_values((array) ($r['dates'] ?? []))] : ['name' => $r, 'dates' => []], $raw));
    }

    // ------------------------------------------------------------------ a match's extras

    /** `predictions` → the provider's numbers (no AI of ours, spec 200). */
    public function prediction(array $raw): ?array
    {
        $p = (array) ($raw[0] ?? []);
        if ($p === []) {
            return null;
        }
        $pct = fn ($v) => $v === null ? null : (float) rtrim((string) $v, '%');
        $side = fn ($t) => [
            'form' => $pct(data_get($t, 'last_5.form')), 'att' => $pct(data_get($t, 'last_5.att')), 'def' => $pct(data_get($t, 'last_5.def')),
            'goals_for' => data_get($t, 'last_5.goals.for.total'), 'goals_against' => data_get($t, 'last_5.goals.against.total'),
            'league_form' => data_get($t, 'league.form'),
        ];

        return [
            'winner' => data_get($p, 'predictions.winner.name'),
            'winner_comment' => data_get($p, 'predictions.winner.comment'),
            'win_or_draw' => data_get($p, 'predictions.win_or_draw'),
            'under_over' => data_get($p, 'predictions.under_over'),
            'goals' => data_get($p, 'predictions.goals'),
            'advice' => data_get($p, 'predictions.advice'),
            'percent' => ['home' => $pct(data_get($p, 'predictions.percent.home')), 'draw' => $pct(data_get($p, 'predictions.percent.draw')), 'away' => $pct(data_get($p, 'predictions.percent.away'))],
            'comparison' => array_values(array_map(fn ($k, $v) => ['type' => $k, 'home' => $pct($v['home'] ?? null), 'away' => $pct($v['away'] ?? null)], array_keys((array) ($p['comparison'] ?? [])), (array) ($p['comparison'] ?? []))),
            'home' => $side(data_get($p, 'teams.home')),
            'away' => $side(data_get($p, 'teams.away')),
        ];
    }

    /**
     * Past meetings, newest first, and the tally from the home side's view.
     *
     * @param  list<array<string, mixed>>  $fixtures  raw fixtures
     * @return array{summary: array<string, int>, matches: list<array<string, mixed>>}
     */
    public function h2h(array $fixtures, int $homeProviderId, int $limit = 10): array
    {
        usort($fixtures, fn ($a, $b) => strcmp((string) data_get($b, 'fixture.date'), (string) data_get($a, 'fixture.date')));
        $done = array_values(array_filter($fixtures, fn ($f) => in_array(data_get($f, 'fixture.status.short'), ['FT', 'AET', 'PEN'], true)));
        $this->prime($done);
        $tally = ['home' => 0, 'draw' => 0, 'away' => 0, 'home_goals' => 0, 'away_goals' => 0];
        foreach ($done as $f) {
            $mine = (int) data_get($f, 'teams.home.id') === $homeProviderId ? 'home' : 'away';
            $theirs = $mine === 'home' ? 'away' : 'home';
            $g = (int) data_get($f, "goals.{$mine}");
            $o = (int) data_get($f, "goals.{$theirs}");
            $tally['home_goals'] += $g;
            $tally['away_goals'] += $o;
            $tally[$g > $o ? 'home' : ($g < $o ? 'away' : 'draw')]++;
        }

        return [
            'summary' => $tally + ['played' => count($done)],
            'matches' => array_map(fn ($f) => [
                'date' => ($d = data_get($f, 'fixture.date')) ? CarbonImmutable::parse($d)->toDateString() : null,
                'competition' => ['name' => data_get($f, 'league.name'), 'logo' => SportsMedia::url(data_get($f, 'league.logo'))],
                'home' => $this->team(data_get($f, 'teams.home')),
                'away' => $this->team(data_get($f, 'teams.away')),
                'home_score' => data_get($f, 'goals.home'),
                'away_score' => data_get($f, 'goals.away'),
            ], array_slice($done, 0, $limit)),
        ];
    }

    /** `injuries?fixture` → who misses it, per side. */
    public function injuries(array $raw, int $homeProviderId): array
    {
        return array_values(array_map(fn ($r) => [
            'side' => (int) data_get($r, 'team.id') === $homeProviderId ? 'home' : 'away',
            'player' => $this->person($r['player'] ?? null),
            'type' => data_get($r, 'player.type'),
            'reason' => data_get($r, 'player.reason'),
        ], $raw));
    }

    /**
     * `odds?fixture` → a few markets from the bookmaker with the most, information only.
     *
     * @return array<string, mixed>|null
     */
    public function odds(array $raw): ?array
    {
        $books = collect((array) data_get($raw, '0.bookmakers', []))->sortByDesc(fn ($b) => count((array) ($b['bets'] ?? [])));
        $book = $books->first();
        if ($book === null) {
            return null;
        }
        // Match winner, both teams score, goals over/under, double chance.
        $wanted = [1 => 'winner', 8 => 'both_score', 5 => 'over_under', 12 => 'double_chance'];
        $bets = collect((array) ($book['bets'] ?? []))->filter(fn ($b) => isset($wanted[(int) ($b['id'] ?? 0)]))
            ->map(fn ($b) => ['key' => $wanted[(int) $b['id']], 'name' => $b['name'] ?? null, 'values' => array_values(array_map(fn ($v) => ['value' => (string) ($v['value'] ?? ''), 'odd' => $v['odd'] ?? null], array_slice((array) ($b['values'] ?? []), 0, 8)))])
            ->sortBy(fn ($b) => array_search($b['key'], array_values($wanted), true))->values()->all();

        return ['bookmaker' => $book['name'] ?? null, 'updated_at' => data_get($raw, '0.update'), 'bets' => $bets];
    }
}
