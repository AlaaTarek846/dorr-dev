<?php

namespace Modules\Freelance\Services\Home;

use App\Models\Country;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as MStatus;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Enums\FreelanceProjectStatus as PStatus;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelancePromo as Promo;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\FreelanceServiceOrder as Order;
use Modules\Freelance\Services\Features\FreelanceCountry;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Market\ServiceCatalog;
use Modules\Freelance\Services\Offers\SpecialOffers;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\User\Models\User;

/**
 * The client's freelance hub in the Dorr user app in one answer (the client redesign, batch B): the promo banners, the few numbers that
 * matter (running projects, offers not seen yet, running orders, consultations ahead, money put into parts this month), what waits for the
 * client (new offers, a delivery to review, a part to fund, a project to fix, special offers), the next consultation, the running projects
 * and a row of ready-made services. Read on open, when the connection returns, when a freelance notice arrives and on a pull — never on a
 * timer.
 */
class ClientHome
{
    /** The projects that count as running on the hub (posted and not over). */
    private const RUNNING = [PStatus::Published, PStatus::Awarded, PStatus::InProgress];

    public function __construct(
        private readonly FreelanceFeatures $features,
        private readonly HomePromos $promos,
        private readonly ClientProjects $projects,
        private readonly ServiceCatalog $catalog,
        private readonly SpecialOffers $specialOffers,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $client): array
    {
        $countryId = FreelanceCountry::of($client);
        $snapshot = $countryId === null ? ['service' => false, 'features' => array_fill_keys(FreelanceFeatures::FEATURES, false)] : $this->features->snapshot($countryId);
        $on = $snapshot['features'];
        $currency = $countryId === null ? null : Country::query()->with('currency')->find($countryId)?->currency;
        $running = array_map(fn (PStatus $s) => $s->value, self::RUNNING);

        return [
            'server_time' => now()->toIso8601String(),
            'service' => (bool) $snapshot['service'],
            'features' => $on,
            'currency' => $currency?->code,
            'decimals' => (int) ($currency?->decimal_places ?? 2),
            'promos' => $this->promos->for(Promo::CLIENT, $countryId, $on),
            'stats' => [
                'running_projects' => Project::query()->where('client_id', $client->id)->whereIn('status', $running)->count(),
                'new_offers' => $this->newOffers($client)->count(),
                'running_orders' => ($on['services'] ?? false) ? Order::query()->where('client_id', $client->id)
                    ->whereHas('agreement', fn ($q) => $q->whereIn('status', [...Order::FILTERS['awaiting'], ...Order::FILTERS['active']]))->count() : 0,
                'upcoming_consultations' => ($on['consultations'] ?? false) ? Consultation::query()->where('client_id', $client->id)
                    ->whereIn('status', [Consultation::PENDING, Consultation::BOOKED])->where('ends_at', '>', now())->count() : 0,
                'funded_month_minor' => (int) Milestone::query()->where('funded_at', '>=', now()->startOfMonth())
                    ->whereHas('agreement', fn ($q) => $q->where('client_id', $client->id))->sum('amount_minor'),
            ],
            'attention' => $this->attention($client, $on),
            'next_consultation' => ($on['consultations'] ?? false) ? $this->nextConsultation($client) : null,
            'projects' => Project::query()->where('client_id', $client->id)->whereIn('status', $running)
                ->with(['specialty.translations', 'skills.translations', 'media'])
                ->latest('updated_at')->limit((int) config('freelance.home.client_projects_max', 3))->get()
                ->map(fn (Project $p) => $this->projects->present($p))->values()->all(),
            'services' => $countryId !== null && ($on['services'] ?? false) ? $this->services($client) : [],
            // Nothing posted or ordered yet: the hub shows how it works instead of empty numbers.
            'is_new' => ! Project::query()->where('client_id', $client->id)->exists() && ! Order::query()->where('client_id', $client->id)->exists(),
        ];
    }

    /**
     * What waits for the client, each with how many and the project the newest one is on (so one tap opens it when there is just one).
     *
     * @param  array<string, bool>  $on
     * @return array<string, mixed>
     */
    private function attention(User $client, array $on): array
    {
        $mine = fn ($q) => $q->where('client_id', $client->id)->where('status', Agreement::ACTIVE);
        $review = Milestone::query()->where('status', MStatus::Delivered->value)->whereHas('agreement', $mine);
        // A part waits for its money while nothing else in that work is funded or being worked on (the same rule as `next_action`).
        $busy = [MStatus::Funded->value, MStatus::InProgress->value, MStatus::Revision->value, MStatus::Delivered->value];
        $fund = Agreement::query()->where('client_id', $client->id)->where('status', Agreement::ACTIVE)
            ->whereHas('milestones', fn ($q) => $q->where('status', MStatus::Unfunded->value))
            ->whereDoesntHave('milestones', fn ($q) => $q->whereIn('status', $busy));
        $fix = Project::query()->where('client_id', $client->id)->whereIn('status', [PStatus::ChangesRequested->value, PStatus::Rejected->value]);
        $offers = $this->newOffers($client);

        return [
            'new_offers' => $this->item($offers->count(), fn () => (clone $offers)->latest('id')->with('project:id,uuid')->first()?->project?->uuid),
            'to_review' => $this->item($review->count(), fn () => (clone $review)->latest('delivered_at')->with('agreement.project:id,uuid')->first()?->agreement?->project?->uuid),
            'to_fund' => $this->item($fund->count(), fn () => (clone $fund)->latest('activated_at')->with('project:id,uuid')->first()?->project?->uuid),
            'to_fix' => $this->item($fix->count(), fn () => (clone $fix)->latest('updated_at')->value('uuid')),
            'special_offers' => ($on['special_offers'] ?? false) ? $this->specialOffers->waiting($client) : 0,
        ];
    }

    /** @return array{count: int, project_uuid: ?string} */
    private function item(int $count, \Closure $project): array
    {
        return ['count' => $count, 'project_uuid' => $count > 0 ? $project() : null];
    }

    /** The offers on my open projects I have not looked at yet. */
    private function newOffers(User $client): \Illuminate\Database\Eloquent\Builder
    {
        return Offer::query()->where('status', OfferStatus::Submitted->value)
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id)->where('status', PStatus::Published->value));
    }

    /** @return array<string, mixed>|null the nearest booked one, else the nearest still waiting for the freelancer */
    private function nextConsultation(User $client): ?array
    {
        $c = Consultation::query()->where('client_id', $client->id)->where('status', Consultation::BOOKED)->where('ends_at', '>', now())->orderBy('starts_at')->with('freelancer')->first()
            ?? Consultation::query()->where('client_id', $client->id)->where('status', Consultation::PENDING)->where('starts_at', '>', now())->orderBy('starts_at')->with('freelancer')->first();

        return $c === null ? null : [
            'uuid' => $c->uuid, 'status' => $c->status, 'title' => $c->title, 'minutes' => $c->minutes, 'freelancer_name' => $c->freelancer?->name,
            'starts_at' => $c->starts_at?->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> the recommended ready-made services, as the market's cards */
    private function services(User $client): array
    {
        $page = $this->catalog->browse($client, ['sort' => 'recommended'], (int) config('freelance.home.client_services_max', 8));
        $this->catalog->warm($page->getCollection());

        return $page->getCollection()->map(fn (FreelanceService $s) => $this->catalog->card($s))->values()->all();
    }
}
