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
            'currency' => $country?->currency?->code,
            'decimals' => (int) ($country?->currency?->decimal_places ?? 2),
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
        ];
        if ($level >= 2) {
            $data['earnings'] += $this->series($f, $days);
            [$starts, $weekly] = $this->bucketStarts($days);
            $data['views'] += ['bucket' => $weekly ? 'week' : 'month', 'series' => $this->views->series($f->id, $starts, $weekly), 'top_services' => $this->views->topServices($f->id, $since, (int) config('freelance.insights.top_specialties', 5))];
            $data['specialties'] = $this->specialties($f, $since);
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

    private function rate(int $part, int $whole): ?int
    {
        return $whole <= 0 ? null : (int) round($part * 100 / $whole);
    }
}
