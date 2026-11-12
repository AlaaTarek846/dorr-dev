<?php

namespace Modules\Freelance\Services\Plans;

use App\Models\Country;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceEarning as Earning;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceServiceOrder as Order;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\FreelanceReviews;

/**
 * «My performance» (FL-L01, phase 4): the freelancer's own numbers over the last days, from what the platform already keeps — the offers
 * they sent and what became of them, the orders of their ready-made services, what they earned, how often their profile and services are looked at (FL-L11), and their name with clients. What a
 * plan opens is `plans.analytics`: 1 = a summary of the last 30 days, 2 = also 90 and 365 days, the earnings over time and the
 * specialties they offer in. Free (0) gets `locked` and the plan that opens it — never an error, so the screen can sell it. Offers that a
 * service order made by itself are not offers the freelancer sent, so they are left out of the funnel (the orders have their own numbers).
 */
class FreelanceInsights
{
    public function __construct(
        private readonly FreelancePerks $perks,
        private readonly FreelancePlans $plans,
        private readonly FreelanceReviews $reviews,
        private readonly FreelanceProfileViews $views,
    ) {}

    /**
     * @param  ?int  $days  asked for; the largest the plan allows that is not above it (else the shortest)
     * @return array<string, mixed>
     */
    public function for(Freelancer $f, ?int $days): array
    {
        $level = $this->perks->analyticsLevel($f);
        $allowed = array_values((array) config("freelance.insights.days.{$level}", []));
        $country = $f->country_id === null ? null : Country::query()->with('currency')->find((int) $f->country_id);

        $out = [
            'level' => $level,
            'locked' => $allowed === [],
            'subscriptions_enabled' => $country !== null && $this->plans->enabled($country->id),
            'allowed_days' => $allowed,
            'days' => null,
            'currency' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(),
            'decimals' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->decimals(),
            // The plan that opens more than this freelancer has (null at the top, or where plans are not sold).
            'upgrade' => $level >= 2 ? null : $this->perks->planForAnalytics($f, $level),
            'data' => null,
        ];
        if ($allowed === []) {
            return $out;
        }

        $asked = $days ?? $allowed[0];
        $days = collect($allowed)->filter(fn (int $d) => $d <= $asked)->max() ?? $allowed[0];
        $since = now()->subDays($days)->startOfDay();

        $data = [
            'offers' => $this->offers($f, $since),
            'services' => $this->services($f, $since),
            'earnings' => ['earned_minor' => (int) Earning::query()->where('freelancer_id', $f->id)->where('status', Earning::RELEASED)->where('released_at', '>=', $since)->sum('amount_minor')],
            'reputation' => $this->reputation($f, $since),
            'views' => $this->views->totals($f->id, $since, $days),
            // FS-103: how many lists of clients he is in (only the count - never whose).
            'saved' => ['lists' => app(\Modules\Freelance\Services\Offers\TalentPool::class)->countFor($f), 'new' => app(\Modules\Freelance\Services\Offers\TalentPool::class)->countFor($f, $since)],
        ];
        if ($level >= 2) {
            $data['earnings'] += $this->series($f, $days);
            [$starts, $weekly] = $this->bucketStarts($days);
            $data['views'] += ['bucket' => $weekly ? 'week' : 'month', 'series' => $this->views->series($f->id, $starts, $weekly), 'top_services' => $this->views->topServices($f->id, $since, (int) config('freelance.insights.top_specialties', 5))];
            $data['specialties'] = $this->specialties($f, $since);
            // FS-704: where the earnings came from, and how the portfolio does.
            $data['earnings'] += ['analysis' => $this->analysis($f, $since, $days, (int) $data['earnings']['earned_minor'])];
            $data['portfolio'] = $this->portfolio($f, $since, $days, (int) ($data['views']['by_source']['portfolio'] ?? 0));
        }

        return ['days' => $days, 'data' => $data] + $out;
    }

    /** @return array<string, int|null> the funnel of the offers sent in the period */
    private function offers(Freelancer $f, Carbon $since): array
    {
        $base = Offer::query()->where('freelancer_id', $f->id)->where('created_at', '>=', $since)->whereHas('project', fn ($q) => $q->whereNull('service_id'));
        $byStatus = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $sent = (int) $byStatus->sum();
        $withdrawn = (int) ($byStatus[OfferStatus::Withdrawn->value] ?? 0);
        $standing = $sent - $withdrawn;
        $viewed = (clone $base)->whereNotNull('viewed_at')->where('status', '!=', OfferStatus::Withdrawn->value)->count();
        $shortlisted = (int) ($byStatus[OfferStatus::Shortlisted->value] ?? 0) + (int) ($byStatus[OfferStatus::Accepted->value] ?? 0);
        $accepted = (int) ($byStatus[OfferStatus::Accepted->value] ?? 0);

        return [
            'sent' => $sent, 'withdrawn' => $withdrawn, 'viewed' => $viewed, 'shortlisted' => $shortlisted, 'accepted' => $accepted,
            'rejected' => (int) ($byStatus[OfferStatus::Rejected->value] ?? 0),
            'view_rate' => $this->rate($viewed, $standing), 'accept_rate' => $this->rate($accepted, $standing),
        ];
    }

    /** @return array<string, int|null> the orders clients placed in the period, by where each stands */
    private function services(Freelancer $f, Carbon $since): array
    {
        $rows = DB::table('freelance_service_orders as o')->leftJoin('freelance_agreements as a', 'a.project_id', '=', 'o.project_id')
            ->where('o.freelancer_id', $f->id)->where('o.created_at', '>=', $since)->selectRaw('a.status as st, count(*) as n')->groupBy('a.status')->get();
        $by = ['awaiting' => 0, 'active' => 0, 'completed' => 0, 'declined' => 0, 'expired' => 0, 'cancelled' => 0];
        foreach ($rows as $r) {
            $by[Order::STATUS_OF[$r->st] ?? 'awaiting'] += (int) $r->n;
        }
        $accepted = $by['active'] + $by['completed'];

        return ['received' => array_sum($by)] + $by + [
            // The orders the freelancer answered: yes, or no (an unanswered one that ran out counts as no). A client taking it back is not an answer.
            'accept_rate' => $this->rate($accepted, $accepted + $by['declined'] + $by['expired']),
        ];
    }

    /** @return array<string, mixed> */
    private function reputation(Freelancer $f, Carbon $since): array
    {
        $stats = $this->reviews->stats($f);

        return array_intersect_key($stats, array_flip(['rating', 'reviews_count', 'completed_projects', 'completion_rate', 'on_time_rate']))
            + ['completed_in_period' => Agreement::query()->where('freelancer_id', $f->id)->where('status', Agreement::COMPLETED)->where('completed_at', '>=', $since)->count()];
    }

    /**
     * What was earned over time: by week for 30 days, by calendar month for 90 and 365 (the last 3 / 12 months, the empty ones too).
     *
     * @return array{bucket: string, series: list<array{from: string, earned_minor: int}>}
     */
    private function series(Freelancer $f, int $days): array
    {
        [$starts, $weekly] = $this->bucketStarts($days);
        $today = now()->startOfDay();
        $n = count($starts);
        $points = array_fill_keys($starts, 0);
        $first = Carbon::parse(array_key_first($points))->startOfDay();

        Earning::query()->where('freelancer_id', $f->id)->where('status', Earning::RELEASED)->where('released_at', '>=', $first)->get(['amount_minor', 'released_at'])
            ->each(function (Earning $e) use (&$points, $weekly, $today, $n) {
                // A week is counted back from today: the bucket `k` holds the days 7·(n-k-1) … 7·(n-k)-1 ago.
                $key = $weekly
                    ? $today->copy()->subDays(7 * (intdiv(abs((int) $e->released_at->copy()->startOfDay()->diffInDays($today)), 7) + 1) - 1)->toDateString()
                    : $e->released_at->copy()->startOfMonth()->toDateString();
                if (isset($points[$key])) {
                    $points[$key] += (int) $e->amount_minor;
                }
            });

        return ['bucket' => $weekly ? 'week' : 'month', 'series' => collect($points)->map(fn (int $minor, string $from) => ['from' => $from, 'earned_minor' => $minor])->values()->all()];
    }

    /**
     * The first day of each bucket the charts use, oldest first: a week back from today for 30 days, a calendar month for 90 and 365.
     *
     * @return array{0: list<string>, 1: bool} the starts and whether the buckets are weeks
     */
    private function bucketStarts(int $days): array
    {
        $weekly = $days <= 30;
        $today = now()->startOfDay();
        $n = $weekly ? (int) ceil($days / 7) : ($days <= 90 ? 3 : 12);
        $starts = [];
        for ($k = 0; $k < $n; $k++) {
            $starts[] = ($weekly ? $today->copy()->subDays(7 * ($n - $k) - 1) : $today->copy()->startOfMonth()->subMonthsNoOverflow($n - 1 - $k))->toDateString();
        }

        return [$starts, $weekly];
    }

    /** @return list<array<string, mixed>> the specialties they offered in the most, with what came of it */
    private function specialties(Freelancer $f, Carbon $since): array
    {
        $rows = DB::table('freelance_offers as o')->join('freelance_projects as p', 'p.id', '=', 'o.project_id')
            ->where('o.freelancer_id', $f->id)->where('o.created_at', '>=', $since)->whereNull('p.service_id')->whereNotNull('p.freelance_specialty_id')
            ->selectRaw("p.freelance_specialty_id as sid, count(*) as sent, sum(case when o.status = 'accepted' then 1 else 0 end) as accepted")
            ->groupBy('p.freelance_specialty_id')->orderByDesc('sent')->limit((int) config('freelance.insights.top_specialties', 5))->get();
        $names = FreelanceSpecialty::query()->with('translations')->whereIn('id', $rows->pluck('sid'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->sid, 'name' => $names->get($r->sid)?->translatedName(), 'sent' => (int) $r->sent, 'accepted' => (int) $r->accepted,
            'accept_rate' => $this->rate((int) $r->accepted, (int) $r->sent),
        ])->values()->all();
    }

    /**
     * FS-704: what the earnings of the period are made of — against the stretch before it, by where they came from (projects, ready-made
     * services, consultations), by specialty and by client — and what a project brings on average. Only released money counts, as everywhere else.
     *
     * @return array<string, mixed>
     */
    private function analysis(Freelancer $f, Carbon $since, int $days, int $earned): array
    {
        $released = fn () => DB::table('freelance_earnings as e')->leftJoin('freelance_projects as p', 'p.id', '=', 'e.project_id')
            ->where('e.freelancer_id', $f->id)->where('e.status', Earning::RELEASED);
        $before = (int) $released()->where('e.released_at', '>=', $since->copy()->subDays($days))->where('e.released_at', '<', $since)->sum('e.amount_minor');
        $rows = $released()->where('e.released_at', '>=', $since)
            ->selectRaw('e.project_id, e.consultation_id, p.service_id, p.freelance_specialty_id as sid, p.client_id as cid, sum(e.amount_minor) as n')
            ->groupBy('e.project_id', 'e.consultation_id', 'p.service_id', 'p.freelance_specialty_id', 'p.client_id')->get();

        $sources = ['projects' => 0, 'services' => 0, 'consultations' => 0];
        $bySpecialty = [];
        $byClient = [];
        foreach ($rows as $r) {
            $n = (int) $r->n;
            $sources[$r->consultation_id !== null ? 'consultations' : ($r->service_id !== null ? 'services' : 'projects')] += $n;
            if ($r->sid !== null) {
                $bySpecialty[(int) $r->sid] = ($bySpecialty[(int) $r->sid] ?? 0) + $n;
            }
            if ($r->cid !== null) {
                $byClient[(int) $r->cid] = ($byClient[(int) $r->cid] ?? 0) + $n;
            }
        }
        arsort($bySpecialty);
        arsort($byClient);
        $limit = (int) config('freelance.insights.top_clients', 5);
        $names = FreelanceSpecialty::query()->with('translations')->whereIn('id', array_slice(array_keys($bySpecialty), 0, $limit))->get()->keyBy('id');
        $clients = \Modules\User\Models\User::query()->whereIn('id', array_slice(array_keys($byClient), 0, $limit))->pluck('name', 'id');
        $paid = $rows->whereNotNull('project_id');
        $projects = $paid->pluck('project_id')->unique()->count();

        return [
            'before_minor' => $before,
            // null when there was nothing before to compare with (a first period is not «up infinity percent»).
            'change_percent' => $before > 0 ? (int) round(($earned - $before) * 100 / $before) : null,
            'sources' => $sources,
            'by_specialty' => collect($bySpecialty)->take($limit)->map(fn (int $n, int $id) => ['id' => $id, 'name' => $names->get($id)?->translatedName(), 'earned_minor' => $n, 'share' => $this->rate($n, $earned)])->values()->all(),
            'top_clients' => collect($byClient)->take($limit)->map(fn (int $n, int $id) => ['name' => $clients[$id] ?? null, 'earned_minor' => $n, 'share' => $this->rate($n, $earned)])->values()->all(),
            'projects_paid' => $projects,
            'average_project_minor' => $projects > 0 ? (int) round($paid->sum(fn ($r) => (int) $r->n) / $projects) : null,
        ];
    }

    /**
     * FS-704, portfolio analytics: how many works there are and how many are verified (FS-601), how often the portfolio and each work were
     * opened (against the stretch before), and the works looked at the most. Counted per day, never per person (FL-L11).
     *
     * @return array<string, mixed>
     */
    private function portfolio(Freelancer $f, Carbon $since, int $days, int $portfolioVisits): array
    {
        $works = $f->portfolioItems()->whereNull('team_id')->whereNull('hidden_by_admin_at')->with('agreement:id,status,portfolio_allowed')->get();
        $views = $this->views->workTotals($f->id, $since, $days);
        // Only the works the freelancer has now are counted (opens of a work since deleted, hidden or moved to a team are not).
        $mine = array_flip($works->pluck('id')->all());
        $opened = array_sum(array_intersect_key($views['by_work'], $mine));
        $before = array_sum(array_intersect_key($views['by_work_before'], $mine));
        $shown = $works->where('visibility', '!=', \Modules\Freelance\Models\FreelancerPortfolioItem::PRIVATE);
        $top = $shown->map(fn ($w) => ['uuid' => $w->uuid, 'title' => $w->title, 'verified' => $w->isVerified(), 'visibility' => $w->visibility, 'views' => (int) ($views['by_work'][$w->id] ?? 0)])
            ->sortByDesc('views')->take((int) config('freelance.insights.top_works', 8))->values()->all();

        return [
            'works' => $works->count(), 'shown' => $shown->count(), 'verified' => $works->filter(fn ($w) => $w->isVerified())->count(),
            'visits' => $portfolioVisits, 'opened' => $opened, 'opened_before' => $before,
            'opened_change_percent' => $before > 0 ? (int) round(($opened - $before) * 100 / $before) : null,
            'top' => $top,
        ];
    }

    private function rate(int $part, int $whole): ?int
    {
        return $whole <= 0 ? null : (int) round($part * 100 / $whole);
    }
}
