<?php

namespace Modules\Sports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sports\Data\SportAdapter;
use Modules\Sports\Events\MatchChanged;
use Modules\Sports\Events\SportsRealtimeEvent;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsMatchDetail;
use Modules\Sports\Models\SportsMatchEvent;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Models\SportsStanding;
use Modules\Sports\Models\SportsTeam;
use Modules\Sports\Support\ApiSportsClient;
use Throwable;

/**
 * The sync engine (docs/sports-plan.md §3): competitions from the provider, a few schedule
 * requests a day, and — only inside the match windows — one live request per tier at the tier's
 * interval (stretched by the governor when the budget is short), details only when something
 * changed, line-ups an hour before, standings after a round. Every change bumps the match's
 * version, goes out on Pusher at once and is announced as a MatchChanged event.
 */
class SportsEngine
{
    /** Matches followed by someone go one tier up; nobody following anyone in it, one down. */
    private const UP = ['minor' => 'normal', 'normal' => 'big', 'big' => 'big'];

    private const DOWN = ['big' => 'normal', 'normal' => 'minor', 'minor' => 'minor'];

    public function __construct(
        private readonly ApiSportsClient $client,
        private readonly SportsGovernor $governor,
        private readonly SportsPresenter $presenter,
        private readonly SportsLibrary $library,
    ) {}

    public function adapter(SportsSport $sport): SportAdapter
    {
        $class = $sport->config()['adapter'] ?? null;
        if ($class === null) {
            throw new \RuntimeException("No adapter for {$sport->key}.");
        }

        return new $class($this->client, $sport->key);
    }

    // ------------------------------------------------------------------ catalog (184–188)

    /**
     * Every current competition of a sport, one request. New ones start off ("off") unless they're
     * in the suggested list; the admin's tier, scope and names are never overwritten.
     *
     * @return array{created: int, updated: int}
     */
    public function importCompetitions(SportsSport $sport, bool $suggest = true): array
    {
        $suggested = array_flip((array) config("sports.suggested.{$sport->key}", []));
        $created = 0;
        $updated = 0;
        foreach ($this->adapter($sport)->competitions() as $c) {
            $row = SportsCompetition::query()->firstOrNew(['sport_id' => $sport->id, 'provider_id' => $c['provider_id']]);
            $new = ! $row->exists;
            $row->fill(collect($c)->only(['name', 'type', 'logo', 'country_name', 'country_code', 'flag', 'season', 'season_start', 'season_end', 'coverage'])->all());
            if ($new) {
                $row->tier = $suggest && isset($suggested[$c['provider_id']]) ? 'big' : 'off';
                $row->scope = self::guessScope($c);
                $row->priority = $suggest && isset($suggested[$c['provider_id']]) ? 100 : 0;
            }
            $row->save();
            $new ? $created++ : $updated++;
        }
        $this->governor->forget();

        return ['created' => $created, 'updated' => $updated];
    }

    /** @param  array<string, mixed>  $c */
    private static function guessScope(array $c): string
    {
        $country = strtolower((string) ($c['country_name'] ?? ''));

        return match (true) {
            $country === 'world' => 'international',
            in_array($country, ['europe', 'asia', 'africa', 'south-america', 'north-america', 'oceania'], true) => 'continental',
            default => 'domestic',
        };
    }

    // ------------------------------------------------------------------ schedule (190)

    /**
     * One day's matches in the active competitions (one request for the whole sport).
     */
    public function syncSchedule(SportsSport $sport, string $date): int
    {
        $competitions = SportsCompetition::query()->where('sport_id', $sport->id)->active()->get()->keyBy('provider_id');
        if ($competitions->isEmpty()) {
            return 0;
        }
        $count = 0;
        foreach ($this->adapter($sport)->schedule($date) as $n) {
            if (($competition = $competitions->get($n['competition']['provider_id'])) !== null) {
                $this->apply($sport, $competition, $n);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Schedules that are due: today and tomorrow every 3 hours, the next 5 days once a day.
     * Uses the reserve (a missed postponement is worse than a slower live minute).
     *
     * @return array<string, int>
     */
    public function syncDueSchedules(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $done = [];
        foreach (SportsSport::query()->where('status', true)->get() as $sport) {
            if (! SportsCompetition::query()->where('sport_id', $sport->id)->active()->exists()) {
                continue;
            }
            for ($d = 0; $d < 7; $d++) {
                $date = $now->addDays($d)->toDateString();
                $key = "sports.schedule.{$sport->key}.{$date}";
                $every = $d <= 1 ? 3 * 3600 : 24 * 3600;
                $last = Cache::get($key);
                if ($last !== null && $now->getTimestamp() - (int) $last < $every) {
                    continue;
                }
                if (! $this->governor->canSpend(1, essential: true)) {
                    return $done;
                }
                try {
                    $done["{$sport->key} {$date}"] = $this->syncSchedule($sport, $date);
                    Cache::put($key, $now->getTimestamp(), now()->addDays(2));
                } catch (Throwable $e) {
                    Log::warning('[sports] schedule '.$sport->key.' '.$date.': '.$e->getMessage());
                    // The plan doesn't cover that day (free plans: around today only): don't spend a
                    // request on it again every hour — try once a day.
                    if (str_contains($e->getMessage(), '"plan"')) {
                        Cache::put($key, $now->getTimestamp() + 24 * 3600 - $every, now()->addDays(2));
                    }
                }
            }
        }
        $this->governor->forget();

        return $done;
    }

    // ------------------------------------------------------------------ live loop (191)

    /**
     * Runs every 30 seconds. Costs nothing outside match windows.
     *
     * @return array{polls: int, details: int, changed: int}
     */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $stats = ['polls' => 0, 'details' => 0, 'changed' => 0];
        if (! SportsSetting::current()->enabled || ! $this->client->configured()) {
            return $stats;
        }

        foreach (SportsSport::query()->where('status', true)->get() as $sport) {
            try {
                $this->tickSport($sport, $now, $stats);
            } catch (Throwable $e) {
                Log::warning('[sports] tick '.$sport->key.': '.$e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * @param  array{polls: int, details: int, changed: int}  $stats
     */
    private function tickSport(SportsSport $sport, CarbonImmutable $now, array &$stats): void
    {
        $window = (int) ($sport->config()['window_minutes'] ?? 135);
        $candidates = SportsMatch::query()->where('sport_id', $sport->id)
            ->whereHas('competition', fn ($q) => $q->active())
            ->where(fn ($q) => $q->whereIn('status', SportsMatch::LIVE)
                ->orWhere(fn ($q) => $q->whereIn('status', ['scheduled', 'suspended'])->whereBetween('starts_at', [$now->subMinutes($window + 60), $now->addMinutes(5)])))
            ->with('competition')->get();
        $lineupsDue = $this->lineupsDue($sport, $now);
        if ($candidates->isEmpty() && $lineupsDue->isEmpty()) {
            return;
        }

        $adapter = $this->adapter($sport);
        $followed = $this->followedTeams($sport);
        $watched = $this->watchedCompetitions($sport);
        $details = collect();

        foreach ($candidates->groupBy(fn (SportsMatch $m) => $this->tierFor($m, $followed, $watched)) as $tier => $group) {
            $key = "sports.poll.{$sport->key}.{$tier}";
            $last = Cache::get($key);
            $interval = $this->governor->interval($sport, $tier, $now);
            if ($last !== null && $now->getTimestamp() - (int) $last < $interval - 5) {
                continue;
            }
            if (! $this->governor->canSpend()) {
                return;
            }
            Cache::put($key, $now->getTimestamp(), now()->addDay());

            $competitions = $group->pluck('competition')->keyBy('provider_id');
            $dates = $group->map(fn ($m) => CarbonImmutable::instance($m->starts_at)->toDateString())->unique()->values()->all();
            $live = collect($adapter->live($competitions->keys()->all(), $dates))->keyBy('provider_id');
            $stats['polls']++;

            foreach ($live as $n) {
                $competition = $competitions->get($n['competition']['provider_id']);
                if ($competition === null) {
                    continue;
                }
                $match = SportsMatch::query()->where('sport_id', $sport->id)->where('provider_id', $n['provider_id'])->first();
                $changes = $this->apply($sport, $competition, $n, $match);
                if ($changes !== []) {
                    $stats['changed']++;
                }
                // Who scored, the card, the minute: worth a details call (the minor tier only if someone follows it).
                $saved = $match ?? SportsMatch::query()->where('sport_id', $sport->id)->where('provider_id', $n['provider_id'])->first();
                if ($saved !== null && $adapter->hasDetails() && $n['events'] === null && $changes !== [] && ($tier !== 'minor' || $this->isFollowed($saved, $followed))) {
                    $details->push($saved);
                }
            }

            // Not in the live answer any more (or never there): finished, postponed, a late start?
            foreach ($group as $m) {
                if ($live->has($m->provider_id)) {
                    continue;
                }
                $started = $m->starts_at !== null && $m->starts_at->lte($now->subMinutes(10));
                $stale = $m->details_synced_at === null || $m->details_synced_at->lte($now->subMinutes(10));
                if (($m->isLive() || $started) && $stale) {
                    $details->push($m);
                }
            }

            // Statistics of big live matches, every few minutes.
            if ($tier === 'big') {
                $every = max(180, $interval * 3);
                $group->filter(fn ($m) => $m->isLive() && ($m->details_synced_at === null || $m->details_synced_at->lte($now->subSeconds($every))))->each(fn ($m) => $details->push($m));
            }
        }

        $details = $details->concat($lineupsDue)->unique('id');
        if ($adapter->hasDetails() && $details->isNotEmpty()) {
            $batch = method_exists($adapter, 'detailsBatch') ? $adapter->detailsBatch() : 20;
            foreach ($details->chunk($batch) as $chunk) {
                if (! $this->governor->canSpend()) {
                    break;
                }
                $byId = $chunk->keyBy('provider_id');
                foreach ($adapter->details($byId->keys()->all()) as $n) {
                    if (($m = $byId->get($n['provider_id'])) !== null) {
                        if ($this->apply($sport, $m->competition, $n, $m, details: true) !== []) {
                            $stats['changed']++;
                        }
                    }
                }
                $stats['details']++;
            }
        } elseif (! $adapter->hasDetails()) {
            // No details endpoint: games that left the live answer are read from their day.
            $gone = $details->groupBy(fn ($m) => CarbonImmutable::instance($m->starts_at)->toDateString());
            foreach ($gone as $date => $ms) {
                if (! $this->governor->canSpend()) {
                    break;
                }
                $byId = $ms->keyBy('provider_id');
                foreach ($adapter->schedule((string) $date) as $n) {
                    if (($m = $byId->get($n['provider_id'])) !== null) {
                        $this->apply($sport, $m->competition, $n, $m, details: true);
                    }
                }
                $stats['details']++;
            }
        }
    }

    /**
     * Line-ups an hour before, for followed or big-tier matches (football gives them ~40 min before).
     *
     * @return Collection<int, SportsMatch>
     */
    private function lineupsDue(SportsSport $sport, CarbonImmutable $now): Collection
    {
        if (! $this->adapter($sport)->hasDetails()) {
            return collect();
        }
        $followed = $this->followedTeams($sport);

        return SportsMatch::query()->where('sport_id', $sport->id)->where('status', 'scheduled')
            ->whereNull('lineups_synced_at')
            ->whereBetween('starts_at', [$now, $now->addMinutes(60)])
            ->where(fn ($q) => $q->whereNull('details_synced_at')->orWhere('details_synced_at', '<=', $now->subMinutes(15)))
            ->whereHas('competition', fn ($q) => $q->active())
            ->with('competition')->get()
            ->filter(fn (SportsMatch $m) => $m->competition->covers('lineups') && ($m->competition->tier === 'big' || $this->isFollowed($m, $followed)))
            ->values();
    }

    /**
     * @param  array<int, true>  $followed  team ids
     * @param  array<int, true>  $watched  competition ids someone follows (directly or a team in it)
     */
    public function tierFor(SportsMatch $m, array $followed, array $watched): string
    {
        $tier = $m->competition?->tier ?? 'off';
        if ($tier === 'off') {
            return 'off';
        }
        if ($this->isFollowed($m, $followed)) {
            return self::UP[$tier];
        }
        // Nobody follows anything in this competition (and it isn't one of the big ones).
        if ($tier !== 'big' && $watched !== [] && ! isset($watched[$m->competition_id])) {
            return self::DOWN[$tier];
        }

        return $tier;
    }

    /** @param  array<int, true>  $followed */
    private function isFollowed(SportsMatch $m, array $followed): bool
    {
        return isset($followed[(int) $m->home_team_id]) || isset($followed[(int) $m->away_team_id]);
    }

    /**
     * Teams someone follows in this sport (filled in by the follows feature).
     *
     * @return array<int, true>
     */
    public function followedTeams(SportsSport $sport): array
    {
        return Cache::remember("sports.followed.{$sport->id}", 120, fn () => SportsFollowIndex::teams($sport->id));
    }

    /** @return array<int, true> */
    public function watchedCompetitions(SportsSport $sport): array
    {
        return Cache::remember("sports.watched.{$sport->id}", 120, fn () => SportsFollowIndex::competitions($sport->id));
    }

    // ------------------------------------------------------------------ applying (the heart)

    /**
     * Save a provider answer for one match and work out what changed.
     *
     * @param  array<string, mixed>  $n
     * @return list<array<string, mixed>>
     */
    public function apply(SportsSport $sport, SportsCompetition $competition, array $n, ?SportsMatch $match = null, bool $details = false): array
    {
        $match ??= SportsMatch::query()->where('sport_id', $sport->id)->where('provider_id', $n['provider_id'])->first();
        $before = $match?->only(['status', 'home_score', 'away_score', 'starts_at', 'elapsed', 'status_code']);
        $isNew = $match === null;

        $changes = DB::transaction(function () use ($sport, $competition, $n, &$match, $details, $before, $isNew) {
            $home = $this->team($sport, $n['home']);
            $away = $this->team($sport, $n['away']);
            $match ??= new SportsMatch(['uuid' => (string) Str::uuid(), 'sport_id' => $sport->id, 'provider_id' => $n['provider_id']]);
            $match->fill([
                'competition_id' => $competition->id,
                'season' => $n['competition']['season'] ?? $competition->season,
                'round' => $n['competition']['round'] ?? $match->round,
                'home_team_id' => $home?->id,
                'away_team_id' => $away?->id,
                'starts_at' => ! empty($n['starts_at']) ? CarbonImmutable::parse($n['starts_at'])->utc() : $match->starts_at,
                'status' => $n['status'],
                'status_code' => $n['status_code'],
                'status_label' => $n['status_label'],
                'elapsed' => $n['elapsed'],
                'elapsed_extra' => $n['elapsed_extra'],
                'home_score' => $n['home_score'],
                'away_score' => $n['away_score'],
                'scores' => $n['scores'],
                'winner' => $n['winner'],
                'venue' => $n['venue'] ?? $match->venue,
                'venue_id' => $n['venue_id'] ?? $match->venue_id,
                'city' => $n['city'] ?? $match->city,
                'referee' => $n['referee'] ?? $match->referee,
                'synced_at' => now(),
            ]);
            if ($details) {
                $match->details_synced_at = now();
            }
            if ($match->status !== ($before['status'] ?? null)) {
                if (in_array($match->status, SportsMatch::LIVE, true) && $match->live_at === null) {
                    $match->live_at = now();
                }
                if ($match->status === 'finished') {
                    $match->finished_at = now();
                    $competition->forceFill(['standings_dirty' => true])->save();
                }
            }

            $changes = $isNew ? [] : $this->diff($before, $match);
            $dirty = $match->isDirty(['status', 'home_score', 'away_score', 'elapsed', 'elapsed_extra', 'starts_at', 'status_code', 'scores']);
            $match->save();

            if (is_array($n['events'] ?? null)) {
                $detailed = $this->syncEvents($match, $n['events'], $isNew);
                // The feed says who scored: that replaces the bare "the score went up".
                $sides = array_column(array_filter($detailed, fn ($c) => $c['type'] === 'goal_detail'), 'side');
                $changes = array_merge(array_values(array_filter($changes, fn ($c) => ! ($c['type'] === 'goal' && in_array($c['side'], $sides, true)))), $detailed);
            }
            if ($details && ($n['statistics'] !== null || $n['lineups'] !== null || ($n['players'] ?? null) !== null)) {
                $row = SportsMatchDetail::query()->firstOrNew(['match_id' => $match->id]);
                if (($n['players'] ?? null) !== null) {
                    $row->players = $n['players'];
                    $this->teamCaptain($home, $n['players']['home'] ?? []);
                    $this->teamCaptain($away, $n['players']['away'] ?? []);
                }
                if ($n['statistics'] !== null) {
                    $row->statistics = $n['statistics'];
                }
                if ($n['lineups'] !== null) {
                    $row->lineups = $n['lineups'];
                    $this->teamCoach($home, $n['lineups']['home'] ?? null);
                    $this->teamCoach($away, $n['lineups']['away'] ?? null);
                    if ($match->lineups_synced_at === null) {
                        $match->lineups_synced_at = now();
                        $changes[] = ['type' => 'lineups'];
                        $this->teamColors($home, $n['lineups']['home'] ?? null);
                        $this->teamColors($away, $n['lineups']['away'] ?? null);
                    }
                }
                $row->save();
                $dirty = true;
            }
            if ($dirty || $changes !== []) {
                $match->version = (int) $match->version + 1;
            }
            $match->save();

            return $changes;
        });

        $match->setRelation('competition', $competition)->setRelation('sport', $sport);
        if (! $isNew && ($match->wasChanged('version'))) {
            $this->broadcast($match, $changes);
        }
        if ($changes !== []) {
            event(new MatchChanged($match, $changes));
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @return list<array<string, mixed>>
     */
    private function diff(?array $before, SportsMatch $m): array
    {
        if ($before === null) {
            return [];
        }
        $changes = [];
        $was = $before['status'];
        if ($was === 'scheduled' && in_array($m->status, SportsMatch::LIVE, true)) {
            $changes[] = ['type' => 'kickoff'];
        }
        if ($was === 'live' && $m->status === 'break') {
            $changes[] = ['type' => 'half_time', 'code' => $m->status_code];
        }
        if ($was === 'break' && $m->status === 'live') {
            $changes[] = ['type' => 'resumed', 'code' => $m->status_code];
        }
        if ($was !== $m->status && in_array($m->status, ['finished', 'postponed', 'cancelled', 'suspended'], true)) {
            $changes[] = ['type' => $m->status, 'winner' => $m->winner];
        }
        // A score that went up without an event feed (or before the details arrive).
        foreach (['home', 'away'] as $side) {
            $old = $before["{$side}_score"];
            $new = $m->{"{$side}_score"};
            if ($new !== null && $old !== null && $new > $old && $m->status !== 'finished') {
                $changes[] = ['type' => 'goal', 'side' => $side, 'home' => $m->home_score, 'away' => $m->away_score, 'minute' => $m->elapsed, 'scorer' => null, 'from_score' => true];
            }
        }
        if ($was === 'scheduled' && $m->status === 'scheduled' && $before['starts_at'] !== null && $m->starts_at !== null
            && abs(CarbonImmutable::instance($before['starts_at'])->diffInMinutes($m->starts_at, true)) >= 5) {
            $changes[] = ['type' => 'time_changed', 'old' => CarbonImmutable::instance($before['starts_at'])->toIso8601String()];
        }

        return $changes;
    }

    /**
     * Store the event feed; new goals and red cards are changes (with who and when). A goal
     * already announced from the score alone is not announced twice.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function syncEvents(SportsMatch $match, array $events, bool $isNew): array
    {
        $known = SportsMatchEvent::query()->where('match_id', $match->id)->pluck('key')->flip();
        $keys = [];
        $changes = [];
        foreach ($events as $e) {
            $keys[] = $e['key'];
            if (isset($known[$e['key']])) {
                if (! empty($e['player_id'])) {
                    SportsMatchEvent::query()->where('match_id', $match->id)->where('key', $e['key'])->whereNull('player_id')
                        ->update(['player_id' => $e['player_id'], 'assist_id' => $e['assist_id'] ?? null]);
                }

                continue;
            }
            SportsMatchEvent::query()->create(['match_id' => $match->id] + collect($e)->only(['key', 'minute', 'extra', 'side', 'type', 'player', 'player_id', 'assist', 'assist_id', 'detail', 'sort'])->all());
            if ($isNew) {
                continue;
            }
            if (in_array($e['type'], SportsMatchEvent::GOALS, true)) {
                $changes[] = ['type' => 'goal_detail', 'side' => $e['side'], 'scorer' => $e['player'], 'minute' => $e['minute'], 'own_goal' => $e['type'] === 'own_goal', 'penalty' => $e['type'] === 'penalty', 'home' => $match->home_score, 'away' => $match->away_score];
            } elseif (in_array($e['type'], ['red', 'second_yellow'], true)) {
                $changes[] = ['type' => 'red_card', 'side' => $e['side'], 'player' => $e['player'], 'minute' => $e['minute']];
            }
        }
        // VAR took one back: the feed no longer has it.
        if ($keys !== []) {
            SportsMatchEvent::query()->where('match_id', $match->id)->whereNotIn('key', $keys)->delete();
        }

        return $changes;
    }

    /** @param  array<string, mixed>  $t */
    private function team(SportsSport $sport, array $t): ?SportsTeam
    {
        if (empty($t['provider_id'])) {
            return null;
        }
        $team = SportsTeam::query()->firstOrNew(['sport_id' => $sport->id, 'provider_id' => $t['provider_id']]);
        $team->fill(array_filter(['name' => $t['name'] ?: null, 'logo' => $t['logo'] ?? null, 'code' => $t['code'] ?? null], fn ($v) => $v !== null));
        if (! empty($t['national'])) {
            $team->national = true;
        }
        if ($team->isDirty() || ! $team->exists) {
            $team->save();
        }

        return $team;
    }

    /**
     * The captain of a team's last match: the poster shows him before the next line-up is out.
     *
     * @param  list<array<string, mixed>>  $players
     */
    private function teamCaptain(?SportsTeam $team, array $players): void
    {
        $c = collect($players)->firstWhere('captain', true);
        if ($team !== null && $c !== null && data_get($team->captain, 'id') !== $c['id']) {
            $team->forceFill(['captain' => ['id' => $c['id'], 'name' => $c['name'], 'number' => $c['number']]])->save();
        }
    }

    /** @param  array<string, mixed>|null  $lineup */
    private function teamCoach(?SportsTeam $team, ?array $lineup): void
    {
        $id = data_get($lineup, 'coach_id');
        if ($team !== null && $id !== null && data_get($team->coach, 'id') !== $id) {
            $team->forceFill(['coach' => array_merge((array) $team->coach, ['id' => $id, 'name' => data_get($lineup, 'coach')])])->save();
        }
    }

    /** @param  array<string, mixed>|null  $lineup */
    private function teamColors(?SportsTeam $team, ?array $lineup): void
    {
        $primary = data_get($lineup, 'colors.primary');
        if ($team !== null && $primary !== null && data_get($team->colors, 'primary') !== $primary) {
            $team->forceFill(['colors' => ['primary' => $primary, 'secondary' => data_get($lineup, 'colors.number')]])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    private function broadcast(SportsMatch $match, array $changes): void
    {
        DB::afterCommit(function () use ($match, $changes) {
            try {
                $match->loadMissing(['home.translations', 'away.translations', 'competition.translations', 'sport']);
                $payload = $this->presenter->match($match, 'UTC') + [
                    // The leader of a race, not the whole classification (Pusher's 10 KB limit).
                    'leader' => data_get($match->scores, 'results.0.abbr') ?? data_get($match->scores, 'results.0.driver'),
                    'changes' => array_values(array_map(fn ($c) => array_intersect_key($c, array_flip(['type', 'side', 'minute', 'scorer', 'player', 'own_goal', 'penalty'])), $changes))];
                event(new SportsRealtimeEvent(['sports.match.'.$match->uuid, 'sports.live'], 'sports.match.updated', $payload));
            } catch (Throwable $e) {
                Log::warning('[sports] broadcast: '.$e->getMessage());
            }
        });
    }

    // ------------------------------------------------------------------ standings (192)

    /**
     * Tables that are due: after a round (dirty, 30 min after), else daily for big and normal,
     * weekly for minor. Top scorers daily for big ones.
     *
     * @return array<string, int>
     */
    public function syncDueStandings(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $done = [];
        // A few per run (the command runs hourly): tables never eat the live budget in one go.
        $left = (int) config('sports.standings_per_run', 6);
        $rank = ['big' => 0, 'normal' => 1, 'minor' => 2];
        $competitions = SportsCompetition::query()->active()->with('sport')->orderByDesc('priority')->get()
            ->filter(fn ($c) => $c->sport?->status && $c->covers('standings') && $c->season !== null)
            ->sortBy(fn ($c) => $rank[$c->tier] ?? 3)->values();
        foreach ($competitions as $c) {
            if ($left <= 0) {
                break;
            }
            $every = match ($c->tier) { 'big', 'normal' => 24 * 3600, default => 7 * 24 * 3600 };
            $due = $c->standings_synced_at === null
                || ($c->standings_dirty && $c->standings_synced_at->lte($now->subMinutes(30)) && ! $this->liveIn($c))
                || $c->standings_synced_at->lte($now->subSeconds($every));
            if ($due && ! $this->refused('standings', $c) && $this->governor->canSpend()) {
                $left--;
                try {
                    $done[$c->name] = $this->syncStandings($c);
                } catch (Throwable $e) {
                    $c->forceFill(['standings_synced_at' => now()])->save();
                    $this->noteRefusal('standings', $c, $e);
                    Log::warning('[sports] standings '.$c->name.': '.$e->getMessage());
                }
            }
            $scorersDue = in_array($c->tier, ['big', 'normal'], true) && $c->covers('top_scorers')
                && ($c->scorers_synced_at === null || $c->scorers_synced_at->lte($now->subDay()));
            if ($left > 0 && $scorersDue && ! $this->refused('scorers', $c) && $this->governor->canSpend()) {
                $left--;
                try {
                    $c->forceFill(['top_scorers' => $this->adapter($c->sport)->topScorers($c->provider_id, (string) $c->season), 'scorers_synced_at' => now()])->save();
                } catch (Throwable $e) {
                    $c->forceFill(['scorers_synced_at' => now()])->save();
                    $this->noteRefusal('scorers', $c, $e);
                    Log::warning('[sports] scorers '.$c->name.': '.$e->getMessage());
                }
            }
        }

        return $done;
    }

    /**
     * A competition's whole season in one request (every round, past and coming): daily for big
     * and normal competitions, weekly for minor ones; a season the plan refuses waits a week.
     *
     * @return array<string, int>
     */
    public function syncDueSeasons(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $done = [];
        $left = (int) config('sports.seasons_per_run', 4);
        $rank = ['big' => 0, 'normal' => 1, 'minor' => 2];
        $competitions = SportsCompetition::query()->active()->with('sport')->orderByDesc('priority')->get()
            ->filter(fn ($c) => $c->sport?->status && $c->season !== null && method_exists($this->adapter($c->sport), 'season'))
            ->sortBy(fn ($c) => $rank[$c->tier] ?? 3)->values();
        foreach ($competitions as $c) {
            $every = $c->tier === 'minor' ? 7 * 24 * 3600 : 24 * 3600;
            if ($left <= 0 || ($c->season_synced_at !== null && $c->season_synced_at->gt($now->subSeconds($every))) || $this->refused('season', $c)) {
                continue;
            }
            if (! $this->governor->canSpend()) {
                break;
            }
            $left--;
            $done[$c->name] = $this->syncSeason($c);
        }

        return $done;
    }

    /** Someone opened a competition whose table was never read: read it now (once, budget allowing). */
    public function syncStandingsIfDue(SportsCompetition $c): bool
    {
        $c->loadMissing('sport');
        if ($c->standings_synced_at !== null || $c->season === null || ! $c->covers('standings') || $this->refused('standings', $c)
            || ! $this->governor->canSpend() || ! Cache::add('sports:standings-open:'.$c->id, true, 120)) {
            return false;
        }
        try {
            return $this->syncStandings($c) > 0;
        } catch (Throwable $e) {
            $c->forceFill(['standings_synced_at' => now()])->save();
            $this->noteRefusal('standings', $c, $e);

            return false;
        }
    }

    /** Someone opened the competition: its season, if not read today (budget and plan allowing). */
    public function syncSeasonIfDue(SportsCompetition $c): bool
    {
        $c->loadMissing('sport');
        if ($c->season === null || ($c->season_synced_at !== null && $c->season_synced_at->gt(now()->subDay())) || $this->refused('season', $c)
            || ! method_exists($this->adapter($c->sport), 'season') || ! $this->governor->canSpend() || ! Cache::add('sports:season:'.$c->id, true, 120)) {
            return false;
        }

        return $this->syncSeason($c) > 0;
    }

    /** Someone opened a team never read this week: its venue, founding year and coach (two requests, kept a week). */
    public function refreshTeamProfile(SportsTeam $team): void
    {
        $team->loadMissing('sport');
        if ($team->sport?->key !== 'football' || ($team->profile_synced_at !== null && $team->profile_synced_at->gt(now()->subDays(7)))) {
            return;
        }
        $views = app(FootballViews::class);
        $profile = $this->library->read('football', 'teams', ['id' => $team->provider_id], (int) config('sports.ttl.team_profile', 604800));
        $coach = $this->library->read('football', 'coachs', ['team' => $team->provider_id], (int) config('sports.ttl.coach', 604800));
        if ($profile['state'] === 'pending' && $coach['state'] === 'pending') {
            return;
        }
        $p = $profile['state'] === 'ok' ? $views->profile((array) $profile['data']) : [];
        $c = $coach['state'] === 'ok' ? $views->coach((array) $coach['data'], $team->provider_id) : null;
        $team->forceFill(array_filter([
            'founded' => $p['founded'] ?? null,
            'venue' => $p['venue'] ?? null,
            'country' => $team->country ?? ($p['country'] ?? null),
            'code' => $team->code ?? ($p['code'] ?? null),
            'coach' => $c !== null ? array_intersect_key($c, array_flip(['id', 'name', 'photo', 'nationality', 'age'])) : null,
        ], fn ($v) => $v !== null) + ['profile_synced_at' => now()])->save();
    }

    public function syncSeason(SportsCompetition $c): int
    {
        $c->forceFill(['season_synced_at' => now()])->save();
        try {
            $rows = $this->adapter($c->sport)->season((string) $c->season, $c->provider_id);
        } catch (Throwable $e) {
            $this->noteRefusal('season', $c, $e);
            Log::warning('[sports] season '.$c->name.': '.$e->getMessage());

            return 0;
        }
        foreach ($rows as $n) {
            $this->apply($c->sport, $c, $n);
        }

        return count($rows);
    }

    /**
     * A team's season in all its competitions (cups too), at most once a day, when someone opens
     * the team. Matches of competitions we don't know are skipped.
     */
    public function syncTeamSeason(SportsTeam $team): bool
    {
        $team->loadMissing('sport');
        $sport = $team->sport;
        if ($sport === null || ! $sport->status || ! method_exists($this->adapter($sport), 'season')
            || ($team->season_synced_at !== null && $team->season_synced_at->gt(now()->subDay())) || ! $this->governor->canSpend()) {
            return false;
        }
        $season = SportsMatch::query()->involving($team->id)->whereNotNull('season')->orderByDesc('starts_at')->value('season');
        if ($season === null || Cache::has(self::refusalKey('team-season:'.$team->id.':'.$season)) || ! Cache::add('sports:team-season:'.$team->id, true, 120)) {
            return false;
        }
        $team->forceFill(['season_synced_at' => now()])->save();
        try {
            $rows = $this->adapter($sport)->season((string) $season, null, $team->provider_id);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), '"plan"')) {
                Cache::put(self::refusalKey('team-season:'.$team->id.':'.$season), true, now()->addDays(7));
            }
            Log::warning('[sports] team season '.$team->name.': '.$e->getMessage());

            return false;
        }
        $competitions = SportsCompetition::query()->where('sport_id', $sport->id)
            ->whereIn('provider_id', array_unique(array_map(fn ($n) => $n['competition']['provider_id'], $rows)))->get()->keyBy('provider_id');
        foreach ($rows as $n) {
            if (($c = $competitions->get($n['competition']['provider_id'])) !== null) {
                $this->apply($sport, $c, $n);
            }
        }

        return true;
    }

    /** Bumped by `sports:plan-changed`: every refusal remembered before it is forgotten at once. */
    public const PLAN_GENERATION = 'sports:plan-generation';

    private static function refusalKey(string $rest): string
    {
        return 'sports:refused:'.(int) Cache::get(self::PLAN_GENERATION, 0).':'.$rest;
    }

    /** The provider's plan doesn't cover this competition's season for that data (a free plan, an old season…). */
    public function refused(string $what, SportsCompetition $c): bool
    {
        return Cache::has(self::refusalKey($what.':'.$c->id.':'.$c->season));
    }

    /** A "plan" answer: don't ask again for a week (or until the season changes). */
    private function noteRefusal(string $what, SportsCompetition $c, Throwable $e): void
    {
        if (str_contains($e->getMessage(), '"plan"')) {
            Cache::put(self::refusalKey($what.':'.$c->id.':'.$c->season), true, now()->addDays(7));
        }
    }

    /**
     * Someone opened a match page: if its events, statistics or line-ups are missing or old, read
     * them now — one request, at most once a minute and a half per match, never from the reserve.
     */
    public function refreshOnOpen(SportsMatch $m): bool
    {
        $sport = $m->sport;
        $competition = $m->competition;
        if ($sport === null || ! $sport->status || $competition === null || $competition->tier === 'off') {
            return false;
        }
        $now = CarbonImmutable::now('UTC');
        $synced = $m->details_synced_at;
        $stale = match (true) {
            $m->isLive() => $synced === null || $synced->lte($now->subMinutes(2)),
            $m->status === 'finished' => $synced === null || ($m->finished_at !== null && $synced->lt($m->finished_at)),
            $m->status === 'scheduled' && $m->starts_at !== null && $m->starts_at->lte($now->addHour()) => $synced === null || $synced->lte($now->subMinutes(15)),
            default => false,
        };
        if (! $stale || ! $this->adapter($sport)->hasDetails() || ! $this->governor->canSpend()) {
            return false;
        }
        if (! Cache::add('sports:open:'.$m->id, true, 90)) {
            return false;
        }
        try {
            foreach ($this->adapter($sport)->details([$m->provider_id]) as $n) {
                if ((int) $n['provider_id'] === (int) $m->provider_id) {
                    $this->apply($sport, $competition, $n, $m, details: true);

                    return true;
                }
            }
        } catch (Throwable $e) {
            Log::warning('[sports] details on open '.$m->id.': '.$e->getMessage());
        }

        return false;
    }

    private function liveIn(SportsCompetition $c): bool
    {
        return SportsMatch::query()->where('competition_id', $c->id)->whereIn('status', SportsMatch::LIVE)->exists();
    }

    public function syncStandings(SportsCompetition $c): int
    {
        $rows = $this->adapter($c->sport)->standings($c->provider_id, (string) $c->season);
        DB::transaction(function () use ($c, $rows) {
            $previous = SportsStanding::query()->where('competition_id', $c->id)->where('season', $c->season)->get()->keyBy(fn ($s) => $s->group_name.'|'.$s->team_id);
            $kept = [];
            foreach ($rows as $r) {
                $team = $this->team($c->sport, $r['team']);
                if ($team === null) {
                    continue;
                }
                $old = $previous->get($r['group'].'|'.$team->id);
                $row = SportsStanding::query()->updateOrCreate(
                    ['competition_id' => $c->id, 'season' => $c->season, 'group_name' => $r['group'], 'team_id' => $team->id],
                    collect($r)->only(['rank', 'points', 'played', 'win', 'draw', 'lose', 'goals_for', 'goals_against', 'goal_diff', 'home', 'away', 'form', 'description'])->all() + [
                        'trend' => $old === null || $old->rank === $r['rank'] ? ($old?->trend ?? 'same') : ($r['rank'] < $old->rank ? 'up' : 'down'),
                        'provider_updated_at' => ! empty($r['updated_at']) ? CarbonImmutable::parse($r['updated_at'])->utc() : now(),
                    ],
                );
                $kept[] = $row->id;
            }
            if ($kept !== []) {
                SportsStanding::query()->where('competition_id', $c->id)->where('season', $c->season)->whereNotIn('id', $kept)->delete();
            }
            $c->forceFill(['standings_synced_at' => now(), 'standings_dirty' => false])->save();
        });

        return count($rows);
    }
}
