<?php

namespace Modules\Sports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsFollow;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Models\SportsStanding;
use Modules\Sports\Models\SportsTeam;
use Modules\Sports\Services\SportsEngine;
use Modules\Sports\Services\SportsFollowService;
use Modules\Sports\Services\SportsPresenter;
use Modules\Sports\Support\SportsMedia;

/**
 * DORR Sports in the app (spec 183–194). All data comes from our own tables — the provider is
 * only ever called by the sync engine. `timezone` (where I am) cuts days and shows times (190).
 */
class SportsController extends Controller
{
    public function __construct(
        private readonly SportsPresenter $presenter,
        private readonly SportsFollowService $follows,
        private readonly SportsEngine $engine,
    ) {}

    /** GET home?date=&sport= — today (or a day) by competition, my teams first, live count. */
    public function home(Request $request)
    {
        $this->on();
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'sport' => ['nullable', 'string', 'max:30'], 'timezone' => ['nullable', 'timezone:all']]);
        $zone = $this->zone($request);
        $me = $request->user();
        $date = $data['date'] ?? CarbonImmutable::now($zone)->toDateString();
        $sports = SportsSport::query()->where('status', true)->orderBy('sort_order')->get();
        $sport = isset($data['sport']) ? $sports->firstWhere('key', $data['sport']) : null;

        $matches = $this->day($date, $zone, $sport)->get();
        [$teams, $competitions] = $this->followedIds($me);
        $mine = $matches->filter(fn ($m) => isset($teams[(int) $m->home_team_id]) || isset($teams[(int) $m->away_team_id]))->values();

        $groups = $matches->groupBy('competition_id')->map(fn ($ms) => [
            'competition' => $this->presenter->competitionRef($ms->first()->competition),
            'followed' => isset($competitions[(int) $ms->first()->competition_id]),
            'matches' => $ms->map(fn ($m) => $this->presenter->match($m, $zone))->values()->all(),
            'sort' => (isset($competitions[(int) $ms->first()->competition_id]) ? 1_000_000 : 0) + (int) $ms->first()->competition->priority,
        ])->sortByDesc('sort')->map(fn ($g) => array_diff_key($g, ['sort' => 1]))->values()->all();

        return ApiResponse::success([
            'date' => $date,
            'sports' => $sports->map(fn ($s) => $this->presenter->sport($s))->values()->all(),
            'live_count' => SportsMatch::query()->whereIn('status', SportsMatch::LIVE)->whereHas('competition', fn ($q) => $q->active())->count(),
            'mine' => $mine->map(fn ($m) => $this->presenter->match($m, $zone))->values()->all(),
            'competitions' => $groups,
            'following' => ['teams' => array_keys($teams), 'competitions' => array_keys($competitions)],
            'preferences' => $this->follows->presentPreferences($this->follows->preferences($me)),
        ], __('api.retrieved'));
    }

    /** GET matches?date=&sport=&competition_id=&team_id=&live=1 */
    public function matches(Request $request)
    {
        $this->on();
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'sport' => ['nullable', 'string', 'max:30'], 'competition_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'], 'live' => ['nullable', 'boolean'], 'mine' => ['nullable', 'boolean'], 'timezone' => ['nullable', 'timezone:all'],
        ]);
        $zone = $this->zone($request);
        $sport = isset($data['sport']) ? SportsSport::query()->where('key', $data['sport'])->first() : null;
        $q = ! empty($data['live'])
            ? SportsMatch::query()->with(SportsPresenter::WITH)->whereIn('status', SportsMatch::LIVE)->whereHas('competition', fn ($q) => $q->active())->orderBy('starts_at')
            : $this->day($data['date'] ?? CarbonImmutable::now($zone)->toDateString(), $zone, $sport);
        $q->when($sport, fn ($q) => $q->where('sport_id', $sport->id))
            ->when($data['competition_id'] ?? null, fn ($q, $id) => $q->where('competition_id', $id))
            ->when($data['team_id'] ?? null, fn ($q, $id) => $q->involving((int) $id));
        if (! empty($data['mine'])) {
            [$teams] = $this->followedIds($request->user());
            $q->where(fn ($w) => $w->whereIn('home_team_id', array_keys($teams) ?: [0])->orWhereIn('away_team_id', array_keys($teams) ?: [0]));
        }

        return ApiResponse::success($q->limit(300)->get()->map(fn ($m) => $this->presenter->match($m, $zone))->values()->all(), __('api.retrieved'));
    }

    /**
     * GET widget — the phone's home-screen widgets: my teams' live or next matches (two weeks
     * ahead) and the biggest live matches now. Small on purpose: it's read every 15 minutes.
     */
    public function widget(Request $request)
    {
        $this->on();
        $zone = $this->zone($request);
        [$teams] = $this->followedIds($request->user());
        $ids = array_keys($teams) ?: [0];
        $mine = SportsMatch::query()->with(SportsPresenter::WITH)
            ->where(fn ($w) => $w->whereIn('home_team_id', $ids)->orWhereIn('away_team_id', $ids))
            ->where(fn ($w) => $w->whereIn('status', SportsMatch::LIVE)->orWhere(fn ($n) => $n->where('status', 'scheduled')->whereBetween('starts_at', [now()->subHours(3), now()->addDays(14)])))
            ->orderByRaw("CASE WHEN status IN ('live', 'break') THEN 0 ELSE 1 END")->orderBy('starts_at')->limit(3)->get();
        $live = SportsMatch::query()->with(SportsPresenter::WITH)->whereIn('status', SportsMatch::LIVE)
            ->whereHas('competition', fn ($q) => $q->active())
            ->join('sports_competitions as c', 'c.id', '=', 'sports_matches.competition_id')
            ->orderByRaw("CASE c.tier WHEN 'big' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")->orderByDesc('c.priority')->orderBy('sports_matches.starts_at')
            ->select('sports_matches.*')->limit(6)->get();

        return ApiResponse::success([
            'mine' => $mine->map(fn ($m) => $this->presenter->match($m, $zone))->values()->all(),
            'live' => $live->map(fn ($m) => $this->presenter->match($m, $zone))->values()->all(),
            'updated_at' => now()->toIso8601String(),
        ], __('api.retrieved'));
    }

    /** GET matches/{match} — the live card's page: events, statistics, line-ups (191). */
    public function match(Request $request, SportsMatch $match)
    {
        $this->on();
        $match->load(SportsPresenter::WITH);
        if ($this->engine->refreshOnOpen($match)) {
            $match->refresh()->load(SportsPresenter::WITH);
        }
        [$teams] = $this->followedIds($request->user());

        return ApiResponse::success($this->presenter->full($match, $this->zone($request)) + [
            'following' => ['home' => isset($teams[(int) $match->home_team_id]), 'away' => isset($teams[(int) $match->away_team_id])],
            'channel' => 'sports.match.'.$match->uuid,
        ], __('api.retrieved'));
    }

    /** GET competitions?sport=&q= — the active ones, by scope (184–188). */
    public function competitions(Request $request)
    {
        $this->on();
        $data = $request->validate(['sport' => ['nullable', 'string', 'max:30'], 'q' => ['nullable', 'string', 'max:60']]);
        $rows = SportsCompetition::query()->active()->with(['translations', 'sport'])
            ->whereHas('sport', fn ($q) => $q->where('status', true)->when($data['sport'] ?? null, fn ($q, $k) => $q->where('key', $k)))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('priority')->orderBy('name')->get();

        return ApiResponse::success($rows->map(fn ($c) => $this->presenter->competition($c))->values()->all(), __('api.retrieved'));
    }

    /** GET competitions/{competition} — table and groups, top scorers, last and next matches (192). */
    public function competition(Request $request, SportsCompetition $competition)
    {
        $this->on();
        if ($competition->tier === 'off') {
            throw SportsException::notFound();
        }
        $zone = $this->zone($request);
        $competition->load(['translations', 'sport']);
        $this->engine->syncStandingsIfDue($competition);
        $standings = SportsStanding::query()->where('competition_id', $competition->id)->where('season', $competition->season)
            ->with('team.translations')->orderBy('group_name')->orderBy('rank')->get();
        $now = now();
        $base = fn () => SportsMatch::query()->with(SportsPresenter::WITH)->where('competition_id', $competition->id);

        return ApiResponse::success($this->presenter->competition($competition) + [
            'standings' => $standings->groupBy('group_name')->map(fn ($rows, $group) => [
                'group' => $group,
                'rows' => $rows->map(fn (SportsStanding $s) => [
                    'rank' => $s->rank, 'team' => $this->presenter->team($s->team), 'points' => $s->points, 'played' => $s->played, 'win' => $s->win, 'draw' => $s->draw,
                    'lose' => $s->lose, 'goals_for' => $s->goals_for, 'goals_against' => $s->goals_against, 'goal_diff' => $s->goal_diff, 'form' => $s->form,
                    'home' => $s->home, 'away' => $s->away,
                    'description' => $s->description, 'trend' => $s->trend,
                ])->values()->all(),
            ])->values()->all(),
            'standings_updated_at' => $standings->max('provider_updated_at')?->toIso8601String() ?? $competition->standings_synced_at?->toIso8601String(),
            'top_scorers' => array_map(fn ($s) => ['photo' => SportsMedia::url($s['photo'] ?? null), 'team_logo' => SportsMedia::url($s['team_logo'] ?? null)] + $s, (array) ($competition->top_scorers ?? [])),
            // Why a tab is empty: "pending" (not read yet) or "unavailable" (the provider has none for this season).
            'standings_state' => $standings->isNotEmpty() ? 'ok' : ($this->engine->refused('standings', $competition) || ! $competition->covers('standings') ? 'unavailable' : 'pending'),
            'scorers_state' => ! empty($competition->top_scorers) ? 'ok' : ($this->engine->refused('scorers', $competition) || ! $competition->covers('top_scorers') ? 'unavailable' : 'pending'),
            'teams' => $this->competitionTeams($competition, $standings),
            'live' => $base()->whereIn('status', SportsMatch::LIVE)->orderBy('starts_at')->get()->map(fn ($m) => $this->presenter->match($m, $zone))->all(),
            'next' => $base()->where('status', 'scheduled')->where('starts_at', '>=', $now)->orderBy('starts_at')->limit(15)->get()->map(fn ($m) => $this->presenter->match($m, $zone))->all(),
            'last' => $base()->where('status', 'finished')->orderByDesc('starts_at')->limit(15)->get()->map(fn ($m) => $this->presenter->match($m, $zone))->all(),
            'following' => $this->follows->isFollowing($request->user(), 'competition', $competition->id) !== null,
        ], __('api.retrieved'));
    }

    /** GET teams?q=&sport= — find a team to follow. */
    public function teams(Request $request)
    {
        $this->on();
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60'], 'sport' => ['nullable', 'string', 'max:30']]);
        $rows = SportsTeam::query()->with(['translations', 'sport'])
            ->whereHas('sport', fn ($q) => $q->where('status', true)->when($data['sport'] ?? null, fn ($q, $k) => $q->where('key', $k)))
            ->where(fn ($w) => $w->where('name', 'like', '%'.$data['q'].'%')->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$data['q'].'%')))
            ->orderByDesc('national')->orderBy('name')->limit(40)->get();

        return ApiResponse::success($rows->map(fn ($t) => $this->presenter->team($t) + ['sport' => $t->sport?->key, 'country' => $t->country])->values()->all(), __('api.retrieved'));
    }

    /** GET teams/{team} — next and last matches, and whether I follow it. */
    public function team(Request $request, SportsTeam $team)
    {
        $this->on();
        $zone = $this->zone($request);
        $team->load(['translations', 'sport']);
        $this->engine->refreshTeamProfile($team);
        $this->engine->syncTeamSeason($team);
        // Every competition it plays (cups too), not only the ones we follow live.
        $base = fn () => SportsMatch::query()->with(SportsPresenter::WITH)->involving($team->id);
        $follow = $this->follows->isFollowing($request->user(), 'team', $team->id);
        $last = $base()->where('status', 'finished')->orderByDesc('starts_at')->limit(20)->get();
        $form = $last->take(5)->map(function (SportsMatch $m) use ($team) {
            $mine = $m->home_team_id === $team->id ? $m->home_score : $m->away_score;
            $theirs = $m->home_team_id === $team->id ? $m->away_score : $m->home_score;

            return ['id' => $m->uuid, 'result' => $mine > $theirs ? 'W' : ($mine < $theirs ? 'L' : 'D'), 'score' => "{$m->home_score}-{$m->away_score}"];
        })->values()->all();
        $tables = SportsStanding::query()->with('competition.translations')->where('team_id', $team->id)->get()
            ->filter(fn (SportsStanding $s) => $s->competition !== null && $s->season === $s->competition->season)
            ->map(fn (SportsStanding $s) => ['competition' => $this->presenter->competitionRef($s->competition), 'group' => $s->group_name, 'rank' => $s->rank, 'points' => $s->points, 'played' => $s->played, 'goal_diff' => $s->goal_diff, 'form' => $s->form, 'description' => $s->description])
            ->values()->all();

        return ApiResponse::success($this->presenter->team($team) + [
            'sport' => $team->sport?->key,
            'country' => $team->country,
            'founded' => $team->founded,
            'venue' => $team->venue === null ? null : ['image' => SportsMedia::url(data_get($team->venue, 'image'))] + $team->venue,
            'coach' => $team->coach === null ? null : ['photo' => SportsMedia::of('coachs', data_get($team->coach, 'id'))] + $team->coach,
            'form' => $form,
            'tables' => $tables,
            'live' => $base()->whereIn('status', SportsMatch::LIVE)->get()->map(fn ($m) => $this->presenter->match($m, $zone))->all(),
            'next' => $base()->where('status', 'scheduled')->where('starts_at', '>=', now())->orderBy('starts_at')->limit(30)->get()->map(fn ($m) => $this->presenter->match($m, $zone))->all(),
            'last' => $last->map(fn ($m) => $this->presenter->match($m, $zone))->all(),
            'follow' => $follow ? ['id' => $follow->id, 'alerts' => $follow->alertMap(), 'no_spoilers' => (bool) $follow->no_spoilers] : null,
        ], __('api.retrieved'));
    }

    // ------------------------------------------------------------------ following & choices (189, 193, 194)

    public function follows(Request $request)
    {
        return ApiResponse::success($this->follows->list($request->user()), __('api.retrieved'));
    }

    /** POST follows {kind: team | competition, target_id, alerts?{…}, no_spoilers?} — also updates one. */
    public function follow(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(SportsFollow::KINDS)],
            'target_id' => ['required', 'integer'],
            'alerts' => ['sometimes', 'array'],
            'alerts.*' => ['boolean'],
            'no_spoilers' => ['sometimes', 'boolean'],
        ]);
        $this->follows->follow($request->user(), $data['kind'], (int) $data['target_id'], $data['alerts'] ?? null, $data['no_spoilers'] ?? null);

        return ApiResponse::success($this->follows->list($request->user()), __('api.updated'));
    }

    public function unfollow(Request $request, int $follow)
    {
        $this->follows->unfollow($request->user(), $follow);

        return ApiResponse::success($this->follows->list($request->user()), __('api.deleted'));
    }

    public function preferences(Request $request)
    {
        return ApiResponse::success($this->follows->presentPreferences($this->follows->preferences($request->user())), __('api.retrieved'));
    }

    public function savePreferences(Request $request)
    {
        $data = $request->validate([
            'no_spoilers' => ['sometimes', 'boolean'],
            'celebration' => ['sometimes', Rule::in(\Modules\Sports\Models\SportsPreference::CELEBRATIONS)],
            'sounds' => ['sometimes', 'boolean'],
            'vibrate' => ['sometimes', 'boolean'],
            'reminder_minutes' => ['sometimes', 'integer', Rule::in([0, 5, 10, 15, 30, 60])],
            'goals_in_quiet' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success($this->follows->presentPreferences($this->follows->savePreferences($request->user(), $data)), __('api.updated'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The competition's teams: the table's, or — while there's no table — every team seen in its matches.
     *
     * @param  Collection<int, SportsStanding>  $standings
     * @return list<array<string, mixed>>
     */
    private function competitionTeams(SportsCompetition $competition, Collection $standings): array
    {
        $teams = $standings->pluck('team')->filter()->unique('id');
        if ($teams->isEmpty()) {
            $ids = SportsMatch::query()->where('competition_id', $competition->id)->get(['home_team_id', 'away_team_id'])
                ->flatMap(fn ($m) => [$m->home_team_id, $m->away_team_id])->filter()->unique()->all();
            $teams = SportsTeam::query()->with('translations')->whereIn('id', $ids)->get();
        }
        [$followed] = $this->followedIds(request()->user());

        return $teams->map(fn (SportsTeam $t) => $this->presenter->team($t) + ['country' => $t->country, 'following' => isset($followed[$t->id])])
            ->sortBy('name')->values()->all();
    }

    /** One local day's matches in active competitions of enabled sports. */
    private function day(string $date, string $zone, ?SportsSport $sport): Builder
    {
        $from = CarbonImmutable::parse($date, $zone)->startOfDay()->utc();

        return SportsMatch::query()->with(SportsPresenter::WITH)
            ->whereBetween('starts_at', [$from, $from->addDay()->subSecond()])
            ->whereHas('competition', fn ($q) => $q->active())
            ->whereHas('sport', fn ($q) => $q->where('status', true))
            ->when($sport, fn ($q) => $q->where('sport_id', $sport->id))
            ->orderBy('starts_at');
    }

    /**
     * @return array{0: array<int, true>, 1: array<int, true>}
     */
    private function followedIds(Model $me): array
    {
        $rows = SportsFollow::query()->ownedBy($me)->get(['kind', 'target_id']);

        return [
            array_fill_keys($rows->where('kind', 'team')->pluck('target_id')->map(fn ($i) => (int) $i)->all(), true),
            array_fill_keys($rows->where('kind', 'competition')->pluck('target_id')->map(fn ($i) => (int) $i)->all(), true),
        ];
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
