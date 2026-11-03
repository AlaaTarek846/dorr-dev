<?php

namespace Modules\Freelance\Services\Consultations;

use App\Models\Country;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\FreelanceConsultationOffer as Offer;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerAvailabilityService;
use Modules\Freelance\Services\Features\FreelanceCountry;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Moderation\Sanctions;
use Modules\Freelance\Services\Offers\ClientOfferViews;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Offers\Loyalty;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\FreelanceReviews;
use Modules\User\Models\User;

/**
 * The consultations clients see and book (FL-L07): the list of published ones, one of them with its rules, and its free times. A freelancer
 * lists several (ConsultationListings does the owner's side and the staff's review); each has its lengths (each at its own price) and its
 * weekly hours in the freelancer's own time zone. A free time is a start on the half hour (the quarter for 15 / 45 minutes) whose whole
 * length fits in those hours, is far enough ahead (`consultations.min_notice_hours`), no further than `consultations.max_days_ahead`, not
 * on a day off, and clear of every consultation the freelancer has accepted — whichever listing it came from. A request alone does not
 * take a time. Times go out as UTC instants: each app shows them on its own clock.
 */
class ConsultationOffers
{
    /** The lengths a consultation can have, in minutes. */
    public const LENGTHS = [15, 30, 45, 60, 90];

    public const MAX_OPTIONS = 4;

    /** A booked consultation keeps this gap free after it, so two never touch. */
    public const GAP_MINUTES = 0;

    public function __construct(
        private readonly FreelancePolicies $policies,
        private readonly FreelanceFeatures $features,
    ) {}

    /** The rules of this country (frozen into each booking). @return array{free_cancel_hours: int, complaint_hours: int, response_hours: int, min_notice_hours: int, max_days_ahead: int, earnings_hold_days: int} */
    public function rules(int $countryId): array
    {
        $p = $this->policies->for($countryId, null);

        return [
            'free_cancel_hours' => max(0, (int) ($p->get('consultations.free_cancel_hours') ?? 24)),
            'complaint_hours' => max(1, (int) ($p->get('consultations.complaint_hours') ?? 24)),
            'response_hours' => max(1, (int) ($p->get('consultations.response_hours') ?? 24)),
            'min_notice_hours' => max(0, (int) ($p->get('consultations.min_notice_hours') ?? 2)),
            'max_days_ahead' => max(1, (int) ($p->get('consultations.max_days_ahead') ?? 30)),
            'earnings_hold_days' => max(0, (int) ($p->get('earnings.hold_days') ?? 0)),
        ];
    }

    /** Published, set up, its freelancer verified and not stopped or paused, and the feature on in the country. */
    public function open(Offer $offer): bool
    {
        $f = $offer->freelancer;

        return $f !== null && $offer->status === Offer::PUBLISHED && $offer->lengths() !== [] && $f->status === FreelancerStatus::Verified && ! $f->trashed()
            && $this->features->allows((int) $offer->country_id, 'consultations') && app(Sanctions::class)->restrictions($f)['work'] === null
            && ! app(FreelancerAvailabilityService::class)->isPaused($f);
    }

    /** The consultation a client may book, else not found. */
    public function bookable(User $client, string $offerUuid): Offer
    {
        $country = FreelanceCountry::of($client) ?? throw new FreelanceException('not_available', 403);
        $offer = Offer::query()->where('uuid', $offerUuid)->where('country_id', $country)->with(['freelancer.availability', 'freelancer.media'])->firstOrFail();
        if (! $this->open($offer)) {
            throw new FreelanceException('consultation_unavailable', 409);
        }

        return $offer;
    }

    /** What a length costs a client: the price, the fee and the total. @return array{price_minor: int, client_fee_minor: int, total_minor: int} */
    public function cost(Freelancer $f, int $priceMinor): array
    {
        $c = app(ClientOfferViews::class)->cost($this->pseudoProject($f), $priceMinor);

        return ['price_minor' => $c['price_minor'], 'client_fee_minor' => $c['client_fee_minor'], 'total_minor' => $c['total_minor']];
    }

    /** What the freelancer keeps of a price. @return array{commission_minor: int, net_minor: int} */
    public function earn(Freelancer $f, int $priceMinor): array
    {
        $p = app(FreelancerOffers::class)->preview($this->pseudoProject($f), $priceMinor, $f);

        return ['commission_minor' => (int) $p['commission_minor'], 'net_minor' => (int) $p['net_minor']];
    }

    /**
     * The free start times of a length of this consultation, from now to the last bookable day.
     *
     * @return list<CarbonImmutable> in UTC, soonest first
     */
    public function slots(Offer $offer, int $minutes): array
    {
        if ($offer->priceFor($minutes) === null) {
            return [];
        }
        $f = $offer->freelancer;
        $rules = $this->rules((int) $offer->country_id);
        $zone = $this->zone($offer);
        $now = CarbonImmutable::now('UTC');
        $earliest = $now->addHours($rules['min_notice_hours']);
        $step = $minutes % 30 === 0 ? 30 : 15;
        $today = $now->setTimezone($zone)->startOfDay();
        $last = $today->addDays($rules['max_days_ahead']);

        // Every accepted consultation of the freelancer is busy time, from whichever listing.
        $busy = Consultation::query()->where('freelancer_id', $f->id)->whereIn('status', Consultations::TAKING)
            ->where('ends_at', '>', $now)->where('starts_at', '<', $last->endOfDay()->utc())->get(['starts_at', 'ends_at'])
            ->map(fn (Consultation $c) => [CarbonImmutable::parse($c->starts_at), CarbonImmutable::parse($c->ends_at)->addMinutes(self::GAP_MINUTES)])->all();
        $off = $f->timeOff()->whereDate('ends_on', '>=', $today->toDateString())->get(['starts_on', 'ends_on'])
            ->map(fn ($t) => [$t->starts_on->toDateString(), $t->ends_on->toDateString()])->all();

        $out = [];
        for ($day = $today; $day->lte($last); $day = $day->addDay()) {
            $date = $day->toDateString();
            foreach ($off as [$from, $to]) {
                if ($date >= $from && $date <= $to) {
                    continue 2;
                }
            }
            foreach ((array) $offer->weekly as $range) {
                if ((int) $range['day'] !== $day->dayOfWeek) {
                    continue;
                }
                $open = $day->setTimeFromTimeString($range['from']);
                $close = $day->setTimeFromTimeString($range['to']);
                for ($start = $open; $start->addMinutes($minutes)->lte($close); $start = $start->addMinutes($step)) {
                    $s = $start->utc();
                    $e = $s->addMinutes($minutes);
                    if ($s->lt($earliest) || $this->clashes($busy, $s, $e)) {
                        continue;
                    }
                    $out[$s->getTimestamp()] = $s;
                }
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** Other consultations of the freelancer (in those statuses) that touch this time. @param  list<string>  $statuses */
    public function overlapping(Freelancer $f, CarbonImmutable $start, CarbonImmutable $end, array $statuses, ?int $exceptId = null): \Illuminate\Database\Eloquent\Builder
    {
        return Consultation::query()->where('freelancer_id', $f->id)->whereIn('status', $statuses)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->subMinutes(self::GAP_MINUTES)->utc());
    }

    /** Is this start still free for this length? */
    public function isFree(Offer $offer, int $minutes, CarbonImmutable $start): bool
    {
        foreach ($this->slots($offer, $minutes) as $s) {
            if ($s->equalTo($start)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------- what a client sees

    /** One page of the consultations a client can book (their country's, newest first), with a word to look for in the title, the text or the name. */
    public function market(User $client, ?string $search, int $perPage): LengthAwarePaginator
    {
        $country = FreelanceCountry::of($client) ?? throw new FreelanceException('not_available', 403);

        return Offer::query()->where('status', Offer::PUBLISHED)->where('country_id', $country)
            ->whereHas('freelancer', fn ($f) => $f->where('status', FreelancerStatus::Verified)->whereNull('freelancers.deleted_at'))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('about', 'like', $term)->orWhereHas('freelancer', fn ($f) => $f->where('name', 'like', $term)));
            })
            ->with(['freelancer.media'])->latest('published_at')->latest('id')->paginate($perPage);
    }

    /**
     * One consultation as a card in the list: who, what, from what price and the first free time.
     *
     * @return array<string, mixed>
     */
    public function card(Offer $offer): array
    {
        $f = $offer->freelancer;
        $country = Country::query()->with('currency')->find((int) $offer->country_id);
        $lengths = $offer->lengths();
        $stats = app(FreelanceReviews::class)->stats($f);
        $first = $lengths[0] ?? null;
        $next = $first === null ? null : ($this->slots($offer, $first['minutes'])[0] ?? null);

        return [
            'uuid' => $offer->uuid,
            'title' => $offer->title,
            'about' => $offer->about,
            'minutes' => array_column($lengths, 'minutes'),
            'from_minor' => $lengths === [] ? null : $this->cost($f, min(array_column($lengths, 'price_minor')))['total_minor'],
            'currency' => $country?->currency?->code,
            'decimals' => (int) ($country?->currency?->decimal_places ?? 2),
            'next_slot' => $next?->toIso8601String(),
            'freelancer' => [
                'uuid' => $f->uuid, 'name' => $f->name, 'headline' => $f->headline, 'avatar_url' => $f->getFirstMediaUrl('avatar') ?: null,
                'rating' => $stats['rating'], 'reviews_count' => $stats['reviews_count'],
            ],
        ];
    }

    /**
     * What a client sees before booking one consultation. For a signed-in client each length carries the loyalty step they hold with this
     * freelancer (FL-L09): `price_minor` is already the price after it, `list_price_minor` the usual one (null: no discount).
     *
     * @return array<string, mixed>
     */
    public function presentForClient(Offer $offer, ?User $client = null): array
    {
        $f = $offer->freelancer;
        $loyalty = app(Loyalty::class);
        $step = $client === null ? null : $loyalty->discountFor($f, (int) $client->id);
        $country = Country::query()->with('currency')->find((int) $offer->country_id);
        $stats = app(FreelanceReviews::class)->stats($f);
        $rules = $this->rules((int) $offer->country_id);
        $first = $offer->lengths()[0] ?? null;
        $next = $first === null ? null : ($this->slots($offer, $first['minutes'])[0] ?? null);

        return [
            'uuid' => $offer->uuid,
            'freelancer' => [
                'uuid' => $f->uuid, 'name' => $f->name, 'headline' => $f->headline, 'avatar_url' => $f->getFirstMediaUrl('avatar') ?: null,
                'rating' => $stats['rating'], 'reviews_count' => $stats['reviews_count'], 'completed_projects' => $stats['completed_projects'],
                'consultations_done' => Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::COMPLETED)->count(),
                // What clients said, from projects and consultations (`kind`).
                'reviews' => app(FreelanceReviews::class)->latestAbout($f, 5),
            ],
            'title' => $offer->title,
            'about' => $offer->about,
            'options' => array_map(function ($o) use ($f, $step) {
                $price = $step === null ? (int) $o['price_minor'] : Loyalty::discounted((int) $o['price_minor'], $step['bp']);
                $off = $price < (int) $o['price_minor'];

                return ['minutes' => $o['minutes']] + $this->cost($f, $price) + ['list_price_minor' => $off ? (int) $o['price_minor'] : null, 'loyalty_bp' => $off ? $step['bp'] : null];
            }, $offer->lengths()),
            'loyalty' => $client === null ? null : $loyalty->view($f, $client),
            'currency' => $country?->currency?->code,
            'decimals' => (int) ($country?->currency?->decimal_places ?? 2),
            'timezone' => $offer->timezone ?: 'UTC',
            'next_slot' => $next?->toIso8601String(),
            'rules' => ['free_cancel_hours' => $rules['free_cancel_hours'], 'complaint_hours' => $rules['complaint_hours'], 'response_hours' => $rules['response_hours'], 'max_days_ahead' => $rules['max_days_ahead']],
        ];
    }

    /**
     * The few consultations of a freelancer a profile shows (none: not offered).
     *
     * @return list<array<string, mixed>>
     */
    public function teaser(Freelancer $f, int $limit = 3): array
    {
        return Offer::query()->where('freelancer_id', $f->id)->where('status', Offer::PUBLISHED)->with('freelancer.availability')->latest('published_at')->get()
            ->filter(fn (Offer $o) => $this->open($o))->take($limit)
            ->map(function (Offer $o) {
                $lengths = $o->lengths();

                return [
                    'uuid' => $o->uuid, 'title' => $o->title, 'minutes' => array_column($lengths, 'minutes'),
                    'from_minor' => $this->cost($o->freelancer, min(array_column($lengths, 'price_minor')))['total_minor'],
                    'currency' => Country::query()->with('currency')->find((int) $o->country_id)?->currency?->code,
                ];
            })->values()->all();
    }

    // -------------------------------------------------------------- inside

    private function zone(Offer $offer): string
    {
        $tz = (string) ($offer->timezone ?: 'UTC');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    /** @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy */
    private function clashes(array $busy, CarbonImmutable $s, CarbonImmutable $e): bool
    {
        foreach ($busy as [$bs, $be]) {
            if ($s->lt($be) && $e->gt($bs)) {
                return true;
            }
        }

        return false;
    }

    /** The fees are worked out as for a project of this country (a consultation has no specialty). */
    private function pseudoProject(Freelancer $f): Project
    {
        $currency = Country::query()->with('currency')->find((int) $f->country_id)?->currency?->code;

        return new Project(['country_id' => (int) $f->country_id, 'freelance_specialty_id' => null, 'currency_code' => $currency]);
    }
}
