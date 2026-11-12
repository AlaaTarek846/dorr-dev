<?php

namespace Modules\Freelance\Services\Consultations;

use App\Models\Country;
use App\Services\General\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\FreelanceConsultationOffer as Offer;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Moderation\Sanctions;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Offers\OfferChecks;
use Modules\Freelance\Services\Plans\PlanFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * A freelancer's consultation listings (FL-L07) and the only door to their status — the same road as a ready-made service:
 *
 *   draft / rejected → (submit) → pending_review → (staff) published | rejected       (no review in the country: straight to published)
 *   published ⇄ paused        an edit of a published or paused one goes back to review where review is on
 *   any but draft → suspended (staff) → paused (staff lifts it; the freelancer resumes)
 *
 * Only a verified freelancer, not limited by the staff, lists one, and at most `consultations.max_active` are published or waiting at once.
 * Switching one on needs a title, a length with its price and weekly hours. A listing with requests or accepted sessions ahead is paused,
 * not deleted; the bookings keep their own copy of the title and the money.
 */
class ConsultationListings
{
    public function __construct(
        private readonly ConsultationOffers $offers,
        private readonly FreelancePolicies $policies,
        private readonly FreelanceNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------- the owner

    /** @return Collection<int, Offer> newest first */
    public function mine(Freelancer $f): Collection
    {
        return Offer::query()->where('freelancer_id', $f->id)->with('freelancer')->latest('id')->get();
    }

    public function find(Freelancer $f, string $uuid): Offer
    {
        return Offer::query()->where('freelancer_id', $f->id)->where('uuid', $uuid)->with('freelancer')->firstOrFail();
    }

    /** @param  array<string, mixed>  $data  already validated */
    public function create(Freelancer $f, array $data): Offer
    {
        $country = (int) $f->country_id;
        $offer = new Offer(['freelancer_id' => $f->id, 'country_id' => $country, 'status' => Offer::DRAFT, 'options' => [], 'weekly' => [], 'timezone' => 'UTC']);
        $this->fill($offer, $f, $data);
        $offer->save();

        return $offer->load('freelancer');
    }

    /** @param  array<string, mixed>  $data  only what is sent changes */
    public function update(Offer $offer, array $data): Offer
    {
        return DB::transaction(function () use ($offer, $data) {
            $o = $this->locked($offer);
            if ($o->status === Offer::SUSPENDED) {
                throw $this->state($o);
            }
            $this->fill($o, $o->freelancer, $data);
            // What clients see changed: it is looked at again before they see it.
            if (in_array($o->status, [Offer::PUBLISHED, Offer::PAUSED], true) && $this->reviewed($o)) {
                $o->fill(['status' => Offer::PENDING, 'submitted_at' => now(), 'status_reason' => null]);
            }
            $o->save();

            return $o;
        });
    }

    /** Sent to the staff (or published at once where they don't look first). */
    public function submit(Offer $offer, Freelancer $f): Offer
    {
        return DB::transaction(function () use ($offer, $f) {
            $o = $this->locked($offer);
            if (! in_array($o->status, [Offer::DRAFT, Offer::REJECTED], true)) {
                throw $this->state($o);
            }
            $this->assertCanList($o, $f);
            $this->assertComplete($o);
            $reviewed = $this->reviewed($o);
            $o->fill(['status' => $reviewed ? Offer::PENDING : Offer::PUBLISHED, 'submitted_at' => now(), 'status_reason' => null]
                + ($reviewed ? [] : ['published_at' => $o->published_at ?? now()]))->save();

            return $o;
        });
    }

    public function pause(Offer $offer): Offer
    {
        return $this->move($offer, [Offer::PUBLISHED], Offer::PAUSED);
    }

    public function resume(Offer $offer, Freelancer $f): Offer
    {
        return DB::transaction(function () use ($offer, $f) {
            $o = $this->locked($offer);
            if ($o->status !== Offer::PAUSED) {
                throw $this->state($o);
            }
            $this->assertCanList($o, $f);
            $o->fill(['status' => Offer::PUBLISHED, 'published_at' => $o->published_at ?? now()])->save();

            return $o;
        });
    }

    /** Not while a request or an accepted session is still ahead. */
    public function delete(Offer $offer): void
    {
        DB::transaction(function () use ($offer) {
            $o = $this->locked($offer);
            if ($this->going($o)) {
                throw new FreelanceException('consultation_has_bookings', 409);
            }
            $o->delete();
        });
    }

    // -------------------------------------------------------------- the staff

    public function approve(Offer $offer, int $adminId): Offer
    {
        $o = $this->decide($offer, [Offer::PENDING], Offer::PUBLISHED, null, $adminId, 'freelance_consultation_listing.approved');
        $this->notifier->notify($o->freelancer, 'consultation.listing_approved', ['title' => (string) $o->title], ['consultation_offer' => $o->uuid]);

        return $o;
    }

    public function reject(Offer $offer, string $reason, int $adminId): Offer
    {
        $o = $this->decide($offer, [Offer::PENDING], Offer::REJECTED, $reason, $adminId, 'freelance_consultation_listing.rejected');
        $this->notifier->notify($o->freelancer, 'consultation.listing_rejected', ['title' => (string) $o->title, 'reason' => $reason], ['consultation_offer' => $o->uuid]);

        return $o;
    }

    /** Stopped at once wherever it stands; the sessions already accepted carry on. */
    public function suspend(Offer $offer, string $reason, int $adminId): Offer
    {
        $o = $this->decide($offer, [Offer::PENDING, Offer::PUBLISHED, Offer::PAUSED, Offer::REJECTED], Offer::SUSPENDED, $reason, $adminId, 'freelance_consultation_listing.suspended');
        $this->notifier->notify($o->freelancer, 'consultation.listing_suspended', ['title' => (string) $o->title, 'reason' => $reason], ['consultation_offer' => $o->uuid]);

        return $o;
    }

    /** Back as paused: the freelancer turns it on again. */
    public function unsuspend(Offer $offer, int $adminId): Offer
    {
        return $this->decide($offer, [Offer::SUSPENDED], Offer::PAUSED, null, $adminId, 'freelance_consultation_listing.unsuspended');
    }

    public function adminPage(?string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        return Offer::query()->where('status', '!=', Offer::DRAFT)->with(['freelancer', 'consultations'])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhereHas('freelancer', fn ($f) => $f->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
            })
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Offer::PENDING])->orderBy('submitted_at')->orderBy('id')->paginate($perPage);
    }

    /** @return array<string, int> */
    public function adminCounts(): array
    {
        $counts = Offer::query()->where('status', '!=', Offer::DRAFT)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return collect(Offer::STATUSES)->reject(fn ($s) => $s === Offer::DRAFT)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    public function findForStaff(string $uuid): Offer
    {
        return Offer::query()->where('uuid', $uuid)->where('status', '!=', Offer::DRAFT)->with('freelancer')->firstOrFail();
    }

    // -------------------------------------------------------------- what is shown

    /**
     * What every page of the form needs: the lengths, the limits, the rules and — for a first listing — the hours of work as a start.
     *
     * @return array<string, mixed>
     */
    public function info(Freelancer $f): array
    {
        $country = Country::query()->with('currency')->find((int) $f->country_id);
        $policy = $this->policies->for((int) $f->country_id, null);

        return [
            'feature_on' => app(\Modules\Freelance\Services\Features\FreelanceFeatures::class)->allows((int) $f->country_id, 'consultations'),
            'lengths' => ConsultationOffers::LENGTHS,
            'max_options' => ConsultationOffers::MAX_OPTIONS,
            'max_active' => $this->maxActive((int) $f->country_id, $f),
            'review' => (bool) ($policy->get('consultations.require_review') ?? true),
            'currency' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(),
            'decimals' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->decimals(),
            'price' => ['min_minor' => 100, 'max_minor' => $policy->get('project.max_budget_minor') === null ? null : (int) $policy->get('project.max_budget_minor')],
            'suggested_weekly' => array_values((array) ($f->availability?->weekly_hours ?? [])),
            'rules' => $this->offers->rules((int) $f->country_id),
            'stats' => [
                'pending' => Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::PENDING)->where('starts_at', '>', now())->count(),
                'upcoming' => Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::BOOKED)->where('starts_at', '>', now())->count(),
                'completed' => Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::COMPLETED)->count(),
                'earned_minor' => (int) Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::COMPLETED)->sum('net_minor'),
            ],
        ];
    }

    /**
     * @param  'owner'|'staff'  $viewer
     * @return array<string, mixed>
     */
    public function present(Offer $o, string $viewer): array
    {
        $f = $o->freelancer;
        $country = Country::query()->with('currency')->find((int) $o->country_id);
        $going = $this->going($o);

        $out = [
            'uuid' => $o->uuid,
            'title' => $o->title,
            'about' => $o->about,
            'status' => $o->status,
            'status_label' => __('freelance.consultation_statuses.'.$o->status),
            'status_reason' => $o->status_reason,
            'options' => array_map(fn ($x) => $x + ($f === null ? [] : $this->offers->earn($f, $x['price_minor'])), $o->lengths()),
            'weekly' => (array) $o->weekly,
            'timezone' => $o->timezone ?: 'UTC',
            'currency' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(),
            'decimals' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->decimals(),
            'live' => $this->offers->open($o),
            'submitted_at' => $o->submitted_at?->toIso8601String(),
            'reviewed_at' => $o->reviewed_at?->toIso8601String(),
            'published_at' => $o->published_at?->toIso8601String(),
            'created_at' => $o->created_at?->toIso8601String(),
            'bookings' => [
                'pending' => Consultation::query()->where('offer_id', $o->id)->where('status', Consultation::PENDING)->where('starts_at', '>', now())->count(),
                'upcoming' => Consultation::query()->where('offer_id', $o->id)->where('status', Consultation::BOOKED)->where('ends_at', '>', now())->count(),
                'completed' => Consultation::query()->where('offer_id', $o->id)->where('status', Consultation::COMPLETED)->count(),
            ],
        ];
        if ($viewer === 'staff') {
            return $out + ['freelancer' => ['id' => $f?->id, 'uuid' => $f?->uuid, 'name' => $f?->name, 'phone' => $f?->phone, 'status' => $f?->status?->value], 'country' => $country?->code];
        }

        return $out + ['can' => [
            'edit' => $o->status !== Offer::SUSPENDED,
            'submit' => in_array($o->status, [Offer::DRAFT, Offer::REJECTED], true),
            'pause' => $o->status === Offer::PUBLISHED,
            'resume' => $o->status === Offer::PAUSED,
            'delete' => ! $going,
        ]];
    }

    // -------------------------------------------------------------- inside

    /** @param  array<string, mixed>  $data */
    private function fill(Offer $o, Freelancer $f, array $data): void
    {
        foreach (['title', 'about'] as $k) {
            if (array_key_exists($k, $data)) {
                $text = $data[$k] === null ? null : trim(strip_tags((string) $data[$k]));
                $o->{$k} = $text === '' ? null : $text;
            }
        }
        // No way to reach each other outside Dorr (FL-308).
        app(OfferChecks::class)->assertNoContact(trim(($o->title ?? '').' '.($o->about ?? '')) ?: null);
        if (array_key_exists('options', $data)) {
            $o->options = collect($data['options'] ?? [])->map(fn ($x) => ['minutes' => (int) $x['minutes'], 'price_minor' => (int) $x['price_minor']])
                ->sortBy('minutes')->values()->all();
            $this->assertPrices($f, $o->options);
        }
        if (array_key_exists('weekly', $data)) {
            $o->weekly = $this->cleanWeekly($data['weekly'] ?? []);
        }
        if (array_key_exists('timezone', $data) && $data['timezone'] !== null) {
            $o->timezone = (string) $data['timezone'];
        }
    }

    /** A title, a length with its price and weekly hours: what clients need to book. */
    private function assertComplete(Offer $o): void
    {
        if (trim((string) $o->title) === '' || $o->lengths() === [] || (array) $o->weekly === []) {
            throw new FreelanceException('consultation_incomplete', 422);
        }
    }

    private function locked(Offer $offer): Offer
    {
        return Offer::query()->whereKey($offer->id)->lockForUpdate()->with('freelancer')->firstOrFail();
    }

    private function state(Offer $o): FreelanceException
    {
        return new FreelanceException('consultation_listing_state', 409, ['status' => __('freelance.consultation_statuses.'.$o->status)], ['status' => $o->status]);
    }

    private function reviewed(Offer $o): bool
    {
        return (bool) ($this->policies->for((int) $o->country_id, null)->get('consultations.require_review') ?? true);
    }

    private function maxActive(int $countryId, ?Freelancer $f = null): int
    {
        $policy = max(1, (int) ($this->policies->for($countryId, null)->get('consultations.max_active') ?? 5));
        // FS-705: a plan raises the listings at once, never lowers them (null = no cap).
        $plan = $f === null ? $policy : app(PlanFeatures::class)->forFreelancer($f, 'consultations_max');

        return $plan === null ? PHP_INT_MAX : max(1, (int) $plan);
    }

    /** Verified, not limited by the staff, and room for one more published or waiting listing. */
    private function assertCanList(Offer $o, Freelancer $f): void
    {
        if ($f->status !== FreelancerStatus::Verified) {
            throw new FreelanceException('service_unverified', 403);
        }
        app(Sanctions::class)->assertAllowed($f, 'work');
        $max = $this->maxActive((int) $o->country_id, $f);
        $live = Offer::query()->where('freelancer_id', $f->id)->whereKeyNot($o->id)->whereIn('status', [Offer::PENDING, Offer::PUBLISHED])->count();
        if ($live >= $max) {
            throw new FreelanceException('consultation_listing_limit', 422, ['max' => $max], ['max' => $max]);
        }
    }

    /** A request or an accepted session still ahead on this listing. */
    private function going(Offer $o): bool
    {
        return Consultation::query()->where('offer_id', $o->id)->whereIn('status', [Consultation::PENDING, Consultation::BOOKED, Consultation::DISPUTED])
            ->where('ends_at', '>', now()->subDay())->exists();
    }

    /** @param  list<string>  $from */
    private function move(Offer $offer, array $from, string $to): Offer
    {
        return DB::transaction(function () use ($offer, $from, $to) {
            $o = $this->locked($offer);
            if (! in_array($o->status, $from, true)) {
                throw $this->state($o);
            }
            $o->fill(['status' => $to])->save();

            return $o;
        });
    }

    /** @param  list<string>  $from */
    private function decide(Offer $offer, array $from, string $to, ?string $reason, int $adminId, string $action): Offer
    {
        return DB::transaction(function () use ($offer, $from, $to, $reason, $adminId, $action) {
            $o = $this->locked($offer);
            if (! in_array($o->status, $from, true)) {
                throw $this->state($o);
            }
            $before = $o->status;
            $o->fill(['status' => $to, 'status_reason' => $reason === null ? null : mb_substr($reason, 0, 500), 'reviewed_at' => now(), 'reviewed_by' => $adminId]
                + ($to === Offer::PUBLISHED ? ['published_at' => $o->published_at ?? now()] : []))->save();
            $this->audit->record($action, $o, ['status' => $before], ['status' => $to, 'reason' => $reason]);

            return $o;
        });
    }

    /** @return list<array{day: int, from: string, to: string}> sorted, ranges of a day never crossing */
    private function cleanWeekly(array $weekly): array
    {
        $rows = collect($weekly)->map(fn ($r) => ['day' => (int) $r['day'], 'from' => (string) $r['from'], 'to' => (string) $r['to']])
            ->sortBy(fn ($r) => sprintf('%d-%s', $r['day'], $r['from']))->values()->all();
        foreach ($rows as $i => $r) {
            if ($r['to'] <= $r['from']) {
                throw new FreelanceException('consultation_hours_invalid', 422);
            }
            $prev = $rows[$i - 1] ?? null;
            if ($prev !== null && $prev['day'] === $r['day'] && $r['from'] < $prev['to']) {
                throw new FreelanceException('consultation_hours_overlap', 422);
            }
        }

        return $rows;
    }

    /** @param  list<array{minutes: int, price_minor: int}>  $options */
    private function assertPrices(Freelancer $f, array $options): void
    {
        $max = $this->policies->for((int) $f->country_id, null)->get('project.max_budget_minor');
        foreach ($options as $x) {
            if ($max !== null && $x['price_minor'] > (int) $max) {
                $currency = app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code();
                throw new FreelanceException('service_above_max', 422, ['max' => number_format((int) $max / 100, 2).' '.$currency], ['max_minor' => (int) $max]);
            }
        }
    }
}
