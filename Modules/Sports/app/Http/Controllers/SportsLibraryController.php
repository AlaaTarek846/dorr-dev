<?php

namespace Modules\Sports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Models\SportsStanding;
use Modules\Sports\Models\SportsTeam;
use Modules\Sports\Services\FootballViews;
use Modules\Sports\Services\SportsEngine;
use Modules\Sports\Services\SportsLibrary;
use Modules\Sports\Services\SportsPresenter;
use Modules\Sports\Support\SportsMedia;

/**
 * DORR Sports, the deep pages (docs/sports-plan.md §10): rounds and leaders of a competition,
 * a team's squad, statistics and transfers, players and coaches, a match's prediction, head to
 * head, injuries and odds, and one search. Football first — each answer comes from sports_cache
 * (SportsLibrary) and says its `state`: ok · pending · unavailable (the provider's plan).
 */
class SportsLibraryController extends Controller
{
    public function __construct(
        private readonly SportsLibrary $library,
        private readonly SportsPresenter $presenter,
        private readonly SportsEngine $engine,
    ) {}

    // ------------------------------------------------------------------ competitions

    /** GET competitions/{competition}/rounds?round= — every round with its dates, the current one, its matches. */
    public function rounds(Request $request, SportsCompetition $competition)
    {
        $this->on();
        $this->football($competition->sport?->key);
        $data = $request->validate(['round' => ['nullable', 'string', 'max:120']]);
        $zone = $this->zone($request);
        if ($competition->season_synced_at === null || $competition->season_synced_at->lt(now()->subDay())) {
            $this->engine->syncSeasonIfDue($competition);
        }

        $read = $this->library->read('football', 'fixtures/rounds', ['league' => $competition->provider_id, 'season' => (string) $competition->season, 'dates' => 'true'], $this->ttl('rounds'));
        $rounds = $read['state'] === 'ok' ? $this->views()->rounds((array) $read['data']) : [];
        if ($rounds === []) {
            // From the matches we have, in order.
            $rounds = SportsMatch::query()->where('competition_id', $competition->id)->whereNotNull('round')
                ->selectRaw('round, MIN(starts_at) as first_at')->groupBy('round')->orderBy('first_at')->get()
                ->map(fn ($r) => ['name' => $r->round, 'dates' => []])->all();
        }
        $current = SportsMatch::query()->where('competition_id', $competition->id)->whereNotNull('round')
            ->where('starts_at', '>=', now()->subHours(3))->orderBy('starts_at')->value('round')
            ?? SportsMatch::query()->where('competition_id', $competition->id)->whereNotNull('round')->orderByDesc('starts_at')->value('round');
        $round = $data['round'] ?? $current ?? ($rounds[0]['name'] ?? null);
        $matches = $round === null ? collect() : SportsMatch::query()->with(SportsPresenter::WITH)->where('competition_id', $competition->id)->where('round', $round)->orderBy('starts_at')->get();

        return ApiResponse::success([
            'rounds' => $rounds,
            'current' => $current,
            'round' => $round,
            'matches' => $matches->map(fn ($m) => $this->presenter->match($m, $zone))->values()->all(),
        ], __('api.retrieved'));
    }

    /** GET competitions/{competition}/leaders?type=goals|assists|yellow|red */
    public function leaders(Request $request, SportsCompetition $competition)
    {
        $this->on();
        $this->football($competition->sport?->key);
        $type = $request->validate(['type' => ['nullable', Rule::in(['goals', 'assists', 'yellow', 'red'])]])['type'] ?? 'goals';
        $endpoint = ['goals' => 'players/topscorers', 'assists' => 'players/topassists', 'yellow' => 'players/topyellowcards', 'red' => 'players/topredcards'][$type];
        $read = $this->library->read('football', $endpoint, ['league' => $competition->provider_id, 'season' => (string) $competition->season], $this->ttl('leaders'));

        return ApiResponse::success([
            'type' => $type,
            'state' => $read['state'],
            'fetched_at' => $read['fetched_at'],
            'rows' => $read['state'] === 'ok' ? $this->views()->leaders((array) $read['data'], $type) : [],
        ], __('api.retrieved'));
    }

    // ------------------------------------------------------------------ teams

    /** GET teams/{team}/squad — by line, with photos and numbers. */
    public function squad(SportsTeam $team)
    {
        $this->on();
        $this->football($team->sport?->key);
        $read = $this->library->read('football', 'players/squads', ['team' => $team->provider_id], $this->ttl('squad'));

        return ApiResponse::success($this->answer($read, fn ($d) => $this->views()->squad((array) $d)), __('api.retrieved'));
    }

    /** GET teams/{team}/statistics?competition= — a season in one competition (the league by default). */
    public function teamStatistics(Request $request, SportsTeam $team)
    {
        $this->on();
        $this->football($team->sport?->key);
        $data = $request->validate(['competition' => ['nullable', 'integer']]);
        $competitions = $this->teamCompetitions($team);
        $competition = isset($data['competition']) ? $competitions->firstWhere('id', (int) $data['competition']) : $competitions->first();
        if ($competition === null) {
            return ApiResponse::success(['state' => 'unavailable', 'fetched_at' => null, 'data' => null, 'competition' => null, 'competitions' => []], __('api.retrieved'));
        }
        $playsToday = SportsMatch::query()->involving($team->id)->whereBetween('starts_at', [now()->subHours(3), now()->addDay()])->exists();
        $read = $this->library->read('football', 'teams/statistics', ['league' => $competition->provider_id, 'season' => (string) $competition->season, 'team' => $team->provider_id], $this->ttl($playsToday ? 'team_stats' : 'team_stats_idle'));

        return ApiResponse::success($this->answer($read, fn ($d) => $this->views()->teamStats((array) $d)) + [
            'competition' => $this->presenter->competitionRef($competition),
            'competitions' => $competitions->map(fn ($c) => $this->presenter->competitionRef($c))->values()->all(),
        ], __('api.retrieved'));
    }

    /** GET teams/{team}/transfers — newest first. */
    public function transfers(SportsTeam $team)
    {
        $this->on();
        $this->football($team->sport?->key);
        $read = $this->library->read('football', 'transfers', ['team' => $team->provider_id], $this->ttl('transfers'));

        return ApiResponse::success($this->answer($read, fn ($d) => $this->views()->transfers((array) $d, $team->provider_id)), __('api.retrieved'));
    }

    // ------------------------------------------------------------------ players & coaches

    /** GET players/{player}?season= — the provider's player id: profile and the season's numbers. */
    public function player(Request $request, int $player)
    {
        $this->on();
        $season = (string) ($request->validate(['season' => ['nullable', 'digits:4']])['season'] ?? $this->season());
        $profile = $this->library->read('football', 'players/profiles', ['player' => $player], $this->ttl('player_profile'));
        $stats = $this->library->read('football', 'players', ['id' => $player, 'season' => $season], $this->ttl('player_stats'));
        $view = $this->views()->player((array) ($profile['data'] ?? []), $stats['state'] === 'ok' ? (array) $stats['data'] : null);
        if ($view === null) {
            throw $profile['state'] === 'pending' ? SportsException::pending() : SportsException::notFound();
        }

        return ApiResponse::success($view + [
            'season' => $season,
            'stats_state' => $stats['state'],
            'fetched_at' => $stats['fetched_at'] ?? $profile['fetched_at'],
        ], __('api.retrieved'));
    }

    /** GET players/{player}/career — clubs, trophies, transfers, injuries and suspensions. */
    public function playerCareer(int $player)
    {
        $this->on();
        $views = $this->views();
        $part = fn (string $endpoint, string $ttl, callable $map) => $this->answer($this->library->read('football', $endpoint, ['player' => $player], $this->ttl($ttl)), $map);

        return ApiResponse::success([
            'teams' => $part('players/teams', 'player_career', fn ($d) => $views->career((array) $d)),
            'trophies' => $part('trophies', 'trophies', fn ($d) => $views->trophies((array) $d)),
            'transfers' => $part('transfers', 'transfers', fn ($d) => $views->transfers((array) $d)),
            'sidelined' => $part('sidelined', 'sidelined', fn ($d) => $views->sidelined((array) $d)),
        ], __('api.retrieved'));
    }

    /** GET coaches/{coach} — the provider's coach id: profile, career, trophies. */
    public function coach(int $coach)
    {
        $this->on();
        $views = $this->views();
        $read = $this->library->read('football', 'coachs', ['id' => $coach], $this->ttl('coach'));
        $view = $read['state'] === 'ok' ? $views->coach((array) $read['data']) : null;
        if ($view === null) {
            throw $read['state'] === 'pending' ? SportsException::pending() : SportsException::notFound();
        }

        return ApiResponse::success($view + [
            'trophies' => $this->answer($this->library->read('football', 'trophies', ['coach' => $coach], $this->ttl('trophies')), fn ($d) => $views->trophies((array) $d)),
        ], __('api.retrieved'));
    }

    // ------------------------------------------------------------------ a match's extras

    /** GET matches/{match}/insights — prediction, head to head, who's missing, odds (information only, where allowed). */
    public function insights(SportsMatch $match)
    {
        $this->on();
        $match->loadMissing(['sport', 'home', 'away', 'competition']);
        $this->football($match->sport?->key);
        if ($match->home === null || $match->away === null) {
            throw SportsException::notFound();
        }
        $views = $this->views();
        $done = $match->status === 'finished';
        $fixture = ['fixture' => $match->provider_id];

        $prediction = $this->library->read('football', 'predictions', $fixture, $this->ttl($done ? 'history' : 'prediction'));
        // The prediction carries the last meetings; else ask for them.
        $meetings = (array) data_get($prediction, 'data.0.h2h', []);
        $h2hState = $prediction['state'];
        if ($meetings === []) {
            $pair = collect([$match->home->provider_id, $match->away->provider_id])->sort()->implode('-');
            $read = $this->library->read('football', 'fixtures/headtohead', ['h2h' => $pair], $this->ttl('h2h'));
            $meetings = (array) ($read['data'] ?? []);
            $h2hState = $read['state'];
        }
        $injuries = $this->library->read('football', 'injuries', $fixture, $this->ttl($done ? 'history' : 'injuries'));

        $odds = null;
        if (! $done && SportsSetting::current()->oddsIn(currentCountry()?->id)) {
            $odds = $this->answer($this->library->read('football', 'odds', $fixture, $this->ttl('odds')), fn ($d) => $views->odds((array) $d));
        }

        return ApiResponse::success([
            'prediction' => $this->answer($prediction, fn ($d) => $views->prediction((array) $d)),
            'h2h' => ['state' => $h2hState, 'data' => $views->h2h(array_values(array_filter($meetings, fn ($f) => (int) data_get($f, 'fixture.id') !== $match->provider_id)), $match->home->provider_id)],
            'injuries' => $this->answer($injuries, fn ($d) => $views->injuries((array) $d, $match->home->provider_id)),
            'odds' => $odds,
            'poster' => $this->presenter->poster($match),
        ], __('api.retrieved'));
    }

    // ------------------------------------------------------------------ search

    /** GET search?q= — competitions and teams (ours, then the provider's), players (the provider's, 4+ letters). */
    public function search(Request $request)
    {
        $this->on();
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q']);
        $like = '%'.$q.'%';
        $competitions = SportsCompetition::query()->active()->with(['translations', 'sport'])
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('country_name', 'like', $like)->orWhereHas('translations', fn ($t) => $t->where('name', 'like', $like)))
            ->orderByDesc('priority')->limit(15)->get();
        $teams = SportsTeam::query()->with('translations')->whereHas('sport', fn ($s) => $s->where('status', true))
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhereHas('translations', fn ($t) => $t->where('name', 'like', $like)))
            ->orderByDesc('national')->orderBy('name')->limit(25)->get();
        $views = $this->views();
        if ($teams->count() < 5 && mb_strlen($q) >= 3) {
            $read = $this->library->read('football', 'teams', ['search' => mb_strtolower($q)], $this->ttl('search'));
            $more = collect((array) ($read['data'] ?? []))->pluck('team')->filter()->values()->all();
            $views->prime($more);
            $known = $teams->pluck('id')->all();
            foreach ($more as $t) {
                $ref = $views->team($t);
                if ($ref !== null && $ref['id'] !== null && ! in_array($ref['id'], $known, true)) {
                    $teams->push(SportsTeam::query()->with('translations')->find($ref['id']));
                    $known[] = $ref['id'];
                }
            }
        }
        $players = [];
        if (mb_strlen($q) >= 4) {
            $read = $this->library->read('football', 'players/profiles', ['search' => mb_strtolower($q)], $this->ttl('search'));
            $players = array_values(array_map(fn ($r) => $views->person($r['player'] ?? null) + [
                'nationality' => data_get($r, 'player.nationality'), 'age' => data_get($r, 'player.age'), 'position' => data_get($r, 'player.position'),
            ], array_slice((array) ($read['data'] ?? []), 0, 25)));
        }

        return ApiResponse::success([
            'competitions' => $competitions->map(fn ($c) => $this->presenter->competition($c))->values()->all(),
            'teams' => $teams->filter()->take(30)->map(fn ($t) => $this->presenter->team($t) + ['country' => $t->country])->values()->all(),
            'players' => $players,
        ], __('api.retrieved'));
    }

    // ------------------------------------------------------------------ shared

    /**
     * The team's competitions this season, its league first (for its statistics).
     *
     * @return \Illuminate\Support\Collection<int, SportsCompetition>
     */
    public function teamCompetitions(SportsTeam $team)
    {
        $ids = SportsMatch::query()->involving($team->id)->where('starts_at', '>=', now()->subYear())->distinct()->pluck('competition_id');
        $inTable = SportsStanding::query()->where('team_id', $team->id)->pluck('competition_id')->all();

        return SportsCompetition::query()->with('translations')->whereIn('id', $ids)->whereNotNull('season')->get()
            ->sortBy(fn ($c) => [in_array($c->id, $inTable, true) ? 0 : 1, $c->type === 'League' || $c->type === 'league' ? 0 : 1, -$c->priority])->values();
    }

    /**
     * @param  array{data: mixed, state: string, fetched_at: ?string}  $read
     * @return array{state: string, fetched_at: ?string, data: mixed}
     */
    private function answer(array $read, callable $map): array
    {
        return ['state' => $read['state'], 'fetched_at' => $read['fetched_at'], 'data' => $read['state'] === 'ok' ? $map($read['data'] ?? []) : null];
    }

    private function views(): FootballViews
    {
        return app(FootballViews::class);
    }

    private function ttl(string $key): int
    {
        return (int) config("sports.ttl.{$key}", 86400);
    }

    /** The football season most active competitions are in (a player's default). */
    private function season(): string
    {
        return (string) (SportsCompetition::query()->active()->whereHas('sport', fn ($s) => $s->where('key', 'football'))->whereNotNull('season')
            ->selectRaw('season, COUNT(*) as n')->groupBy('season')->orderByDesc('n')->value('season') ?? now()->year);
    }

    private function football(?string $sport): void
    {
        if ($sport !== 'football') {
            throw SportsException::notFound();
        }
    }

    private function zone(Request $request): string
    {
        foreach ([$request->input('timezone'), $request->user()?->getAttribute('timezone')] as $z) {
            if (is_string($z) && in_array($z, timezone_identifiers_list(), true)) {
                return $z;
            }
        }

        return (string) config('app.timezone', 'UTC');
    }

    private function on(): void
    {
        if (! SportsSetting::current()->onIn(currentCountry()?->id)) {
            throw SportsException::off();
        }
    }
}
