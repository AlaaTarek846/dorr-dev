<?php

namespace Modules\Sports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Support\ApiSportsClient;

/**
 * The budget governor (docs/sports-plan.md §3.4). One daily budget for every sport:
 *  - a reserve (admin's %) is always kept for schedules and postponements;
 *  - it plans the rest of the day — the live windows left in each tier of each sport, at the
 *    tier's interval — and, when that's more than what's left, stretches the intervals just
 *    enough (each sport keeps at least its guaranteed share);
 *  - so the same code runs on 100 requests a day (intervals of minutes) or 7,500 (every minute).
 */
class SportsGovernor
{
    /** Live polls that also need a details call, roughly. */
    private const DETAILS_FACTOR = 0.3;

    public function __construct(private readonly ApiSportsClient $client) {}

    public function reserve(): int
    {
        return (int) ceil($this->client->dailyLimit() * SportsSetting::current()->reserve_percent / 100);
    }

    /** May a call be made now? Essential ones (schedules) may use the reserve. */
    public function canSpend(int $requests = 1, bool $essential = false): bool
    {
        return $this->client->leftToday() - ($essential ? 0 : $this->reserve()) >= $requests;
    }

    /** Seconds between live polls for this sport and tier, right now. */
    public function interval(SportsSport $sport, string $tier, ?CarbonImmutable $now = null): int
    {
        $base = SportsSetting::current()->secondsFor($tier);
        $factor = $this->factors($now ?? CarbonImmutable::now('UTC'))[$sport->key] ?? 1.0;

        return (int) min((int) config('sports.max_seconds', 1800), max((int) config('sports.min_seconds', 30), round($base * $factor)));
    }

    /**
     * How much each sport's intervals must stretch to fit the rest of the day (1 = not at all).
     *
     * @return array<string, float>
     */
    public function factors(CarbonImmutable $now): array
    {
        return Cache::remember('sports.governor.factors', 300, function () use ($now) {
            $plan = $this->plan($now);
            $total = array_sum($plan);
            $available = max(0, $this->client->leftToday() - $this->reserve());
            if ($total <= 0 || $total <= $available) {
                return array_map(fn () => 1.0, $plan);
            }
            if ($available === 0) {
                return array_map(fn () => 60.0, $plan);
            }

            $shares = SportsSport::query()->whereIn('key', array_keys($plan))->pluck('min_share_percent', 'key');
            $out = [];
            foreach ($plan as $sport => $calls) {
                $share = max(((int) ($shares[$sport] ?? 5)) / 100, $calls / $total);
                $out[$sport] = max(1.0, $calls / max(1, $available * $share));
            }

            return $out;
        });
    }

    /**
     * Calls the rest of today would take at the tiers' own intervals, per sport.
     *
     * @return array<string, float>
     */
    public function plan(CarbonImmutable $now): array
    {
        $end = $now->endOfDay();
        $plan = [];
        $settings = SportsSetting::current();

        foreach (SportsSport::query()->where('status', true)->get() as $sport) {
            $window = (int) ($sport->config()['window_minutes'] ?? 135);
            $matches = SportsMatch::query()->where('sport_id', $sport->id)
                ->whereNotIn('status', SportsMatch::DONE)
                ->whereBetween('starts_at', [$now->subMinutes($window), $end])
                ->with('competition:id,tier')->get(['id', 'competition_id', 'starts_at', 'status']);

            $calls = 0.0;
            foreach ($matches->groupBy(fn ($m) => $m->competition?->tier ?? 'off') as $tier => $group) {
                if ($tier === 'off') {
                    continue;
                }
                $minutes = $this->windowMinutes($group, $now, $end, $window);
                $calls += $minutes * 60 / $settings->secondsFor($tier) * (1 + self::DETAILS_FACTOR);
            }
            $plan[$sport->key] = $calls;
        }

        return $plan;
    }

    /**
     * Minutes covered by the union of the matches' windows, from now to the end of the day.
     *
     * @param  Collection<int, SportsMatch>  $matches
     */
    public function windowMinutes(Collection $matches, CarbonImmutable $now, CarbonImmutable $end, int $window): int
    {
        $spans = $matches->map(fn ($m) => [
            max($now->getTimestamp(), CarbonImmutable::instance($m->starts_at)->subMinutes(5)->getTimestamp()),
            min($end->getTimestamp(), CarbonImmutable::instance($m->starts_at)->addMinutes($window)->getTimestamp()),
        ])->filter(fn ($s) => $s[1] > $s[0])->sortBy(0)->values();

        $total = 0;
        $current = null;
        foreach ($spans as [$from, $to]) {
            if ($current === null || $from > $current[1]) {
                if ($current !== null) {
                    $total += $current[1] - $current[0];
                }
                $current = [$from, $to];
            } else {
                $current[1] = max($current[1], $to);
            }
        }
        if ($current !== null) {
            $total += $current[1] - $current[0];
        }

        return (int) ceil($total / 60);
    }

    public function forget(): void
    {
        Cache::forget('sports.governor.factors');
    }
}
