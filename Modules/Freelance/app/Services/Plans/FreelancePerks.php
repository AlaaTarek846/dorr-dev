<?php

namespace Modules\Freelance\Services\Plans;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelanceSanction as Sanction;
use Modules\Freelance\Models\FreelanceSubscription as Subscription;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Skills\FreelanceSkillBadges;

/**
 * What a plan shows and opens beyond offers and commission (FL-L01, phase 4), for a freelancer right now:
 *   - the badge by their name, wherever a client reads about them;
 *   - the weight (`boost`) in the «recommended» order — only that order: every order a client picks (cheapest, fastest, newest…) stays plain;
 *   - the performance numbers (`analytics`: 0 none, 1 basic, 2 full);
 *   - room for more ready-made services.
 * They come only from a subscription that gives its plan now (trial, paid, or a failed renewal inside its grace days), in a country where the
 * `subscriptions` switch is on, on a plan that is still offered — else the freelancer is on Free and has none. A work limit or a
 * suspension takes the boost away (the badge is what they paid for and stays). Keep one instance for a whole list: it remembers each
 * freelancer, and `warm()` reads many at once.
 */
class FreelancePerks
{
    public const NONE = ['plan' => null, 'name' => null, 'rank' => 0, 'badge' => false, 'boost' => 0, 'analytics' => 0, 'services_max' => null];

    /** How long what was read is kept: a plan that changes is seen within seconds even where this object lives on (a worker, a cached controller). */
    private const KEEP_SECONDS = 10;

    /** @var array<int, array<string, mixed>> */
    private array $memo = [];

    private ?object $memoRequest = null;

    private float $memoAt = 0.0;

    public function __construct(private readonly FreelancePlans $plans) {}

    /** What is remembered belongs to one request and a few seconds; a new request (or later) starts clean. */
    private function fresh(): void
    {
        $request = request();
        if ($this->memoRequest !== $request || microtime(true) - $this->memoAt > self::KEEP_SECONDS) {
            $this->memo = [];
            $this->memoRequest = $request;
            $this->memoAt = microtime(true);
        }
    }

    /** @return array{plan: ?string, name: ?string, rank: int, badge: bool, boost: int, analytics: int, services_max: ?int} */
    public function of(Freelancer $f): array
    {
        $this->fresh();
        if (! isset($this->memo[$f->id])) {
            $this->warm([$f]);
        }

        return $this->memo[$f->id];
    }

    /** Reads the perks of many freelancers in two queries (the plans they hold, the work limits on them). @param iterable<Freelancer|null> $freelancers */
    public function warm(iterable $freelancers): void
    {
        $this->fresh();
        $todo = collect($freelancers)->filter()->unique('id')->reject(fn (Freelancer $f) => isset($this->memo[$f->id]))->values();
        if ($todo->isEmpty()) {
            return;
        }
        $ids = $todo->pluck('id')->all();
        // The newest subscription that gives its plan wins.
        $subs = Subscription::query()->giving()->whereIn('freelancer_id', $ids)->with('plan')->orderBy('id')->get()->keyBy('freelancer_id');
        $limited = Sanction::query()->active()->where('subject_type', 'freelancer')->whereIn('subject_id', $ids)
            ->whereIn('type', [Sanction::WORK_LIMIT, Sanction::SUSPENSION])->pluck('subject_id')->flip();

        foreach ($todo as $f) {
            $plan = $f->country_id !== null && $this->plans->enabled((int) $f->country_id) ? $subs->get($f->id)?->plan : null;
            $this->memo[$f->id] = $plan === null || ! $plan->status ? self::NONE : [
                'plan' => $plan->slug, 'name' => $plan->text('name'), 'rank' => (int) $plan->rank, 'badge' => (bool) $plan->badge,
                'boost' => $limited->has($f->id) ? 0 : max(0, (int) $plan->boost),
                'analytics' => max(0, (int) $plan->analytics), 'services_max' => $plan->services_max === null ? null : (int) $plan->services_max,
            ];
        }
    }

    /** @return array{plan: string, name: ?string}|null what a client sees by the name: the plan, when it has a badge */
    public function badge(?Freelancer $f): ?array
    {
        if ($f === null) {
            return null;
        }
        $p = $this->of($f);

        return $p['badge'] && $p['plan'] !== null ? ['plan' => $p['plan'], 'name' => $p['name']] : null;
    }

    public function boost(Freelancer $f): int
    {
        return (int) $this->of($f)['boost'];
    }

    public function analyticsLevel(Freelancer $f): int
    {
        return (int) $this->of($f)['analytics'];
    }

    /** Ready-made services at once: the larger of the policy's number and the plan's, so a plan never lowers it. */
    public function servicesLimit(Freelancer $f, int $policyMax): int
    {
        $max = $this->of($f)['services_max'];

        return $max === null ? $policyMax : max($policyMax, $max);
    }

    /**
     * The best plan weight of the freelancer whose id is in `$column`, as a subquery: the same rules as `of()` in one query for a list
     * that is ordered by it. Sub-selects for a whole page cost one query, however many freelancers there are.
     */
    public function boostQuery(string $column): QueryBuilder
    {
        $now = now();

        return DB::table('freelance_subscriptions as fs')
            ->join('freelance_plans as fp', 'fp.id', '=', 'fs.plan_id')
            ->whereColumn('fs.freelancer_id', $column)
            ->where('fp.status', true)->where('fp.boost', '>', 0)
            ->where(fn ($w) => $w
                ->where(fn ($a) => $a->whereIn('fs.status', [Subscription::TRIALING, Subscription::ACTIVE])
                    ->where(fn ($p) => $p->where('fs.period_end', '>', $now)->orWhere('fs.auto_renew', true)))
                ->orWhere(fn ($d) => $d->where('fs.status', Subscription::PAST_DUE)->where('fs.grace_until', '>', $now)))
            ->whereNotExists(fn ($x) => $x->from('freelance_sanctions as sn')->whereColumn('sn.subject_id', $column)->where('sn.subject_type', 'freelancer')
                ->whereIn('sn.type', [Sanction::WORK_LIMIT, Sanction::SUSPENSION])->whereNull('sn.lifted_at')
                ->where(fn ($e) => $e->whereNull('sn.ends_at')->orWhere('sn.ends_at', '>', $now)))
            ->selectRaw('coalesce(max(fp.boost), 0)');
    }

    /** The «recommended» order of a query of one country: the freelancers on a better plan first, then those with more verified skill badges. */
    public function orderByBoost(Builder $query, string $column, int $countryId): Builder
    {
        if ($this->plans->enabled($countryId)) {
            $query->orderByDesc($this->boostQuery($column));
        }

        // The verified skill badges (FL-L08) break a tie of the plan.
        return $query->orderByDesc(app(FreelanceSkillBadges::class)->countQuery($column));
    }

    /** @return array{slug: string, name: ?string}|null the lowest plan that opens more than `$level` of the numbers and is for sale in the freelancer's country */
    public function planForAnalytics(Freelancer $f, int $level): ?array
    {
        if ($f->country_id === null || ! $this->plans->enabled((int) $f->country_id)) {
            return null;
        }
        $plan = FreelancePlan::query()->where('status', true)->where('analytics', '>', $level)
            ->whereHas('prices', fn ($p) => $p->where('country_id', $f->country_id)->where('status', true))->orderBy('rank')->first();

        return $plan === null ? null : ['slug' => $plan->slug, 'name' => $plan->text('name')];
    }
}
