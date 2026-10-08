<?php

namespace Modules\Sports\Services;

use Carbon\CarbonImmutable;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsMatchEvent;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Models\SportsTeam;
use Modules\Sports\Support\SportsMedia;

/** How a match, a competition or a team looks in the apps and in live updates. */
class SportsPresenter
{
    public const WITH = ['sport', 'competition.translations', 'home.translations', 'away.translations'];

    /**
     * @return array<string, mixed>
     */
    public function match(SportsMatch $m, string $zone): array
    {
        $start = $m->starts_at ? CarbonImmutable::instance($m->starts_at) : null;

        return [
            'id' => $m->uuid,
            'sport' => $m->sport?->key,
            'competition' => $m->competition ? $this->competitionRef($m->competition) : null,
            'round' => $m->round,
            'home' => $m->home ? $this->team($m->home) : null,
            'away' => $m->away ? $this->team($m->away) : null,
            'starts_at' => $start?->toIso8601String(),
            'local_date' => $start?->setTimezone($zone)->toDateString(),
            'local_time' => $start?->setTimezone($zone)->format('H:i'),
            'status' => $m->status,
            'status_code' => $m->status_code,
            'minute' => $m->elapsed,
            'minute_extra' => $m->elapsed_extra,
            'home_score' => $m->home_score,
            'away_score' => $m->away_score,
            'periods' => array_values((array) data_get($m->scores, 'periods', [])),
            // Races (F1) and fights (MMA): what they have instead of a score.
            'title' => $m->home_team_id === null ? ($m->round ?? $m->competition?->displayName()) : null,
            'race' => data_get($m->scores, 'race'),
            'fight' => data_get($m->scores, 'fight'),
            'winner' => $m->winner,
            'version' => (int) $m->version,
            // The minute keeps ticking on the phone from here until the next update.
            'synced_at' => $m->synced_at?->toIso8601String(),
        ];
    }

    /**
     * Everything for the match page: venue, events, statistics, line-ups.
     *
     * @return array<string, mixed>
     */
    public function full(SportsMatch $m, string $zone): array
    {
        $m->loadMissing(['events', 'details']);

        return $this->match($m, $zone) + [
            'venue' => $m->venue,
            'city' => $m->city,
            'referee' => $m->referee,
            'status_label' => $m->status_label,
            'events' => $m->events->sortBy([['minute', 'asc'], ['extra', 'asc'], ['sort', 'asc']])->values()->map(fn (SportsMatchEvent $e) => $this->event($e))->all(),
            'statistics' => array_values((array) ($m->details?->statistics ?? [])),
            'lineups' => $this->lineups($m),
            // The players' match sheet (ratings, captain…) and the poster (football).
            'players' => $this->players($m),
            'poster' => $m->sport?->key === 'football' && $m->home_team_id !== null ? $this->poster($m) : null,
            'results' => array_values((array) data_get($m->scores, 'results', [])),
            'coverage' => [
                'events' => (bool) $m->competition?->covers('events'),
                'statistics' => (bool) $m->competition?->covers('statistics'),
                'lineups' => (bool) $m->competition?->covers('lineups'),
                'standings' => (bool) $m->competition?->covers('standings'),
            ],
            'details_synced_at' => $m->details_synced_at?->toIso8601String(),
        ];
    }

    /**
     * The match poster: venue photo, both coaches, both captains (this match's, else the last
     * one's), the referee — the provider has no referee photos, just a name.
     *
     * @return array<string, mixed>
     */
    public function poster(SportsMatch $match): array
    {
        $match->loadMissing(['details', 'home', 'away']);
        $players = (array) ($match->details?->players ?? []);
        $lineups = (array) ($match->details?->lineups ?? []);
        $side = function (string $s, ?SportsTeam $team) use ($players, $lineups) {
            $captain = collect($players[$s] ?? [])->firstWhere('captain', true) ?? $team?->captain;
            $coachId = data_get($lineups, "{$s}.coach_id") ?? data_get($team?->coach, 'id');

            return [
                'coach' => $coachId || data_get($lineups, "{$s}.coach") ? ['id' => $coachId, 'name' => data_get($lineups, "{$s}.coach") ?? data_get($team?->coach, 'name'), 'photo' => SportsMedia::of('coachs', $coachId)] : null,
                'captain' => $captain ? ['id' => $captain['id'] ?? null, 'name' => $captain['name'] ?? null, 'number' => $captain['number'] ?? null, 'photo' => SportsMedia::of('players', $captain['id'] ?? null)] : null,
            ];
        };
        [$referee, $from] = array_pad(array_map('trim', explode(',', (string) $match->referee, 2)), 2, null);
        $venueId = $match->venue_id ?? data_get($match->home?->venue, 'id');

        return [
            'venue' => ['name' => $match->venue ?? data_get($match->home?->venue, 'name'), 'city' => $match->city, 'image' => SportsMedia::of('venues', $venueId), 'capacity' => data_get($match->home?->venue, 'capacity')],
            'referee' => $referee !== '' ? ['name' => $referee, 'country' => $from] : null,
            'home' => $side('home', $match->home),
            'away' => $side('away', $match->away),
        ];
    }

    /**
     * Line-ups with each player's photo, rating and whether he's the captain.
     *
     * @return array<string, mixed>|null
     */
    private function lineups(SportsMatch $m): ?array
    {
        $lineups = $m->details?->lineups;
        if (! is_array($lineups)) {
            return null;
        }
        $sheet = collect((array) ($m->details?->players ?? []))->map(fn ($ps) => collect($ps)->keyBy('id'));
        foreach (['home', 'away'] as $side) {
            foreach (['start', 'subs'] as $part) {
                foreach ((array) data_get($lineups, "{$side}.{$part}", []) as $i => $p) {
                    $row = $sheet->get($side)?->get($p['id'] ?? 0);
                    $lineups[$side][$part][$i] = $p + [
                        'photo' => SportsMedia::of('players', $p['id'] ?? null),
                        'rating' => $row['rating'] ?? null,
                        'captain' => (bool) ($row['captain'] ?? false),
                        'goals' => (int) ($row['goals'] ?? 0),
                        'yellow' => (int) ($row['yellow'] ?? 0),
                        'red' => (int) ($row['red'] ?? 0),
                    ];
                }
            }
            if (isset($lineups[$side])) {
                $lineups[$side]['coach_photo'] = SportsMedia::of('coachs', data_get($lineups, "{$side}.coach_id"));
            }
        }

        return $lineups;
    }

    /** @return array{home: list<array<string, mixed>>, away: list<array<string, mixed>>}|null */
    private function players(SportsMatch $m): ?array
    {
        $sheet = $m->details?->players;
        if (! is_array($sheet) || $sheet === []) {
            return null;
        }
        $out = [];
        foreach (['home', 'away'] as $side) {
            $out[$side] = collect((array) ($sheet[$side] ?? []))->filter(fn ($p) => ($p['minutes'] ?? 0) > 0)
                ->map(fn ($p) => $p + ['photo' => SportsMedia::of('players', $p['id'] ?? null)])
                ->sortByDesc(fn ($p) => $p['rating'] ?? 0)->values()->all();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function event(SportsMatchEvent $e): array
    {
        return ['minute' => $e->minute, 'extra' => $e->extra, 'side' => $e->side, 'type' => $e->type, 'player' => $e->player, 'player_id' => $e->player_id, 'assist' => $e->assist, 'assist_id' => $e->assist_id, 'detail' => $e->detail];
    }

    /**
     * @return array<string, mixed>
     */
    public function team(SportsTeam $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->displayName(),
            'code' => $t->code,
            'logo' => SportsMedia::url($t->logo),
            'national' => (bool) $t->national,
            'color' => data_get($t->colors, 'primary'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function competitionRef(SportsCompetition $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->displayName(),
            'logo' => SportsMedia::url($c->logo),
            'country' => $c->country_name,
            'flag' => SportsMedia::url($c->flag),
            'tier' => $c->tier,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function competition(SportsCompetition $c): array
    {
        return $this->competitionRef($c) + [
            'sport' => $c->sport?->key,
            'type' => $c->type,
            'scope' => $c->scope,
            'country_code' => $c->country_code,
            'season' => $c->season,
            'coverage' => $c->coverage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sport(SportsSport $s): array
    {
        return ['key' => $s->key, 'name' => $s->name(), 'emoji' => $s->emoji()];
    }
}
