<?php

namespace Modules\Freelance\Services\Home;

use App\Models\Country;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as MStatus;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\FreelanceEarning as Earning;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceProjectInvitation as Invitation;
use Modules\Freelance\Models\FreelancePromo as Promo;
use Modules\Freelance\Models\FreelanceServiceOrder as Order;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Projects\FreelanceReviews;

/**
 * The freelancer app's home in one answer (the redesign, batch B): the promo banners, the few numbers that matter (this month's earnings,
 * what is waiting, live offers and running projects, the rating), what is waiting for an answer, the next consultation and the work with
 * the nearest dates. Read on open, when the connection returns and when a notice arrives — never on a timer.
 */
class FreelancerHome
{
    public function __construct(private readonly FreelanceFeatures $features, private readonly FreelanceReviews $reviews, private readonly HomePromos $promos) {}

    /** @return array<string, mixed> */
    public function for(Freelancer $f): array
    {
        $countryId = $f->country_id === null ? null : (int) $f->country_id;
        $on = $countryId === null ? array_fill_keys(FreelanceFeatures::FEATURES, false) : $this->features->snapshot($countryId)['features'];
        $country = $countryId === null ? null : Country::query()->with('currency')->find($countryId);
        $reviews = $this->reviews->stats($f);

        return [
            'server_time' => now()->toIso8601String(),
            'features' => $on,
            'promos' => $this->promos->for(Promo::FREELANCER, $countryId, $on),
            'stats' => [
                'currency' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(),
                'decimals' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->decimals(),
                'earned_month_minor' => (int) Earning::query()->where('freelancer_id', $f->id)->where('status', Earning::RELEASED)->where('released_at', '>=', now()->startOfMonth())->sum('amount_minor'),
                'pending_minor' => (int) Earning::query()->where('freelancer_id', $f->id)->where('status', Earning::PENDING)->sum('amount_minor'),
                'live_offers' => Offer::query()->where('freelancer_id', $f->id)
                    ->whereIn('status', [OfferStatus::Submitted->value, OfferStatus::Viewed->value, OfferStatus::Shortlisted->value])
                    ->whereHas('project', fn ($q) => $q->whereNull('service_id'))->count(),
                'active_projects' => Agreement::query()->where('freelancer_id', $f->id)->where('status', Agreement::ACTIVE)->count(),
                'rating' => $reviews['rating'],
                'reviews_count' => $reviews['reviews_count'],
                'completed_projects' => $reviews['completed_projects'],
            ],
            'attention' => [
                'orders_awaiting' => ($on['services'] ?? false) ? Order::query()->where('freelancer_id', $f->id)->inStatus('awaiting')->count() : 0,
                'consultations_pending' => ($on['consultations'] ?? false) ? Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::PENDING)->count() : 0,
                'invitations_pending' => ($on['invitations'] ?? false) ? Invitation::query()->where('freelancer_id', $f->id)->where('status', Invitation::SENT)->count() : 0,
                'revisions' => Milestone::query()->where('status', MStatus::Revision->value)->whereHas('agreement', fn ($q) => $q->where('freelancer_id', $f->id)->where('status', Agreement::ACTIVE))->count(),
            ],
            'next_consultation' => ($on['consultations'] ?? false) ? $this->nextConsultation($f) : null,
            'work' => $this->work($f),
        ];
    }

    /** @return array<string, mixed>|null a request waiting for my answer first, else the nearest booked one */
    private function nextConsultation(Freelancer $f): ?array
    {
        $c = Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::PENDING)->where('starts_at', '>', now())->orderBy('starts_at')->with('client')->first()
            ?? Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::BOOKED)->where('ends_at', '>', now())->orderBy('starts_at')->with('client')->first();

        return $c === null ? null : [
            'uuid' => $c->uuid, 'status' => $c->status, 'title' => $c->title, 'minutes' => $c->minutes, 'client_name' => $c->client?->name,
            'starts_at' => $c->starts_at?->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(),
        ];
    }

    /**
     * The running projects with the part being worked on now, the nearest due date first.
     *
     * @return list<array<string, mixed>>
     */
    private function work(Freelancer $f): array
    {
        $live = [MStatus::Funded->value, MStatus::InProgress->value, MStatus::Revision->value, MStatus::Delivered->value];

        return Agreement::query()->where('freelancer_id', $f->id)->where('status', Agreement::ACTIVE)
            ->with(['project:id,uuid,title', 'client', 'milestones' => fn ($q) => $q->whereIn('status', $live)->orderBy('position')])
            ->latest('activated_at')->limit(20)->get()
            ->map(function (Agreement $a) {
                /** @var Milestone|null $m */
                $m = $a->milestones->first();
                $status = $m?->status instanceof MStatus ? $m->status->value : $m?->status;
                $due = $m?->started_at !== null && $m->duration_days !== null ? $m->started_at->copy()->addDays($m->duration_days) : null;

                return [
                    'project_uuid' => $a->project?->uuid,
                    'title' => $a->project?->title,
                    'client_name' => $a->client?->name,
                    'milestone' => $m === null ? null : ['title' => $m->title, 'status' => $status, 'due_at' => $due?->toIso8601String()],
                ];
            })
            ->sortBy(fn (array $w) => $w['milestone']['due_at'] ?? '9999')
            ->take((int) config('freelance.home.work_max', 3))->values()->all();
    }
}
