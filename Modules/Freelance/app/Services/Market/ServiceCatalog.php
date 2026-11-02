<?php

namespace Modules\Freelance\Services\Market;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceService as Service;
use Modules\Freelance\Models\FreelanceServiceAddon;
use Modules\Freelance\Models\FreelanceServiceOrder as Order;
use Modules\Freelance\Models\FreelanceServiceTier;
use Modules\Freelance\Services\Features\FreelanceCountry;
use Modules\Freelance\Services\Offers\ClientOfferViews;
use Modules\Freelance\Services\Plans\FreelancePerks;
use Modules\Freelance\Services\Projects\FreelanceReviews;
use Modules\User\Models\User;

/**
 * The ready-made services a client finds (FL-L04): published ones of verified freelancers in the client's own country (the price is in
 * its currency and its rules apply), searched by words, specialty, price and days, and what one level with some extras costs in all —
 * the client fee on top, as an offer would show it.
 */
class ServiceCatalog
{
    /** `recommended` (the default) puts the freelancers on a better plan first, then the newest; every other order is plain (FL-L01 phase 4). */
    public const SORTS = ['recommended', 'newest', 'price_low', 'price_high', 'popular'];

    public function __construct(private readonly FreelancerServices $services, private readonly FreelancePerks $perks) {}

    /** @param  array{search?: ?string, specialty_id?: ?int, min_price?: ?int, max_price?: ?int, max_days?: ?int, sort?: ?string, freelancer?: ?string}  $f */
    public function browse(User $client, array $f, int $perPage): LengthAwarePaginator
    {
        $country = FreelanceCountry::of($client) ?? throw new FreelanceException('not_available', 403);
        $basic = fn ($q) => $q->where('level', 'basic');
        $sort = $f['sort'] ?? 'recommended';

        return $this->visible($country)
            ->with(['tiers', 'media', 'freelancer.media', 'specialty.translations', 'country.currency'])
            ->withMin(['tiers as starting_price' => $basic], 'price_minor')
            ->withCount(['orders as completed_orders' => fn ($q) => $q->inStatus('completed')])
            ->when(! empty($f['search']), function ($q) use ($f) {
                $term = '%'.addcslashes((string) $f['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when(! empty($f['specialty_id']), fn ($q) => $q->where('freelance_specialty_id', (int) $f['specialty_id']))
            ->when(! empty($f['freelancer']), fn ($q) => $q->whereHas('freelancer', fn ($w) => $w->where('uuid', $f['freelancer'])))
            // The basic level is the cheapest and the starting price.
            ->when(isset($f['min_price']), fn ($q) => $q->whereHas('tiers', fn ($t) => $basic($t)->where('price_minor', '>=', (int) $f['min_price'])))
            ->when(isset($f['max_price']), fn ($q) => $q->whereHas('tiers', fn ($t) => $basic($t)->where('price_minor', '<=', (int) $f['max_price'])))
            ->when(isset($f['max_days']), fn ($q) => $q->whereHas('tiers', fn ($t) => $basic($t)->where('delivery_days', '<=', (int) $f['max_days'])))
            ->when($sort === 'recommended', fn ($q) => $this->perks->orderByBoost($q, (new Service)->getTable().'.freelancer_id', $country))
            ->when($sort === 'price_low', fn ($q) => $q->orderBy('starting_price'))
            ->when($sort === 'price_high', fn ($q) => $q->orderByDesc('starting_price'))
            ->when($sort === 'popular', fn ($q) => $q->orderByDesc('completed_orders'))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    /** One the client may see and order, else not found. */
    public function find(User $client, string $uuid): Service
    {
        $country = FreelanceCountry::of($client) ?? throw new FreelanceException('not_available', 403);

        return $this->visible($country)->where('uuid', $uuid)->with(['tiers', 'addons', 'media', 'freelancer.media', 'specialty.translations', 'country.currency'])->firstOrFail();
    }

    /** Reads the plans of the freelancers of a page of services at once (the cards then ask for no more). @param iterable<Service> $services */
    public function warm(iterable $services): void
    {
        $this->perks->warm(collect($services)->map(fn (Service $s) => $s->freelancer));
    }

    /** @return array<string, mixed> the card in the list */
    public function card(Service $s): array
    {
        $full = $this->services->present($s, 'client');

        return [
            'uuid' => $full['uuid'], 'title' => $full['title'], 'specialty' => $full['specialty'], 'currency' => $full['currency'], 'decimals' => $full['decimals'],
            'starting_price_minor' => $full['starting_price_minor'], 'delivery_days' => $full['tiers'][0]['delivery_days'] ?? null,
            'levels' => count($full['tiers']), 'billing' => $full['billing'], 'cover' => $full['images'][0]['url'] ?? null,
            'completed_orders' => (int) ($s->completed_orders ?? 0),
            'freelancer' => $this->freelancer($s, false),
            // FL-L10: the freelancer is at their cap of running projects (a client may wait in line).
            'at_capacity' => $s->freelancer !== null && app(FreelanceCapacity::class)->isFull($s->freelancer),
        ];
    }

    /** @return array<string, mixed> the whole page, with the freelancer's numbers */
    public function detail(Service $s): array
    {
        return $this->services->present($s, 'client') + [
            'completed_orders' => Order::query()->where('service_id', $s->id)->inStatus('completed')->count(),
            'freelancer' => $this->freelancer($s, true),
            // The hours the freelancer has to accept an order (said before ordering).
            'answer_hours' => (int) (app(\Modules\Freelance\Services\Policy\FreelancePolicies::class)->for((int) $s->country_id, $s->freelance_specialty_id)->get('services.answer_hours') ?? 48),
            // FL-L05: how a monthly one runs (said before subscribing).
            'subscription' => ! $s->monthly() ? null : [
                'period_days' => ServiceSubscriptions::periodDays(), 'renew_window_days' => ServiceSubscriptions::renewWindowDays(),
                'grace_days' => ServiceSubscriptions::graceDays(), 'notice_days' => ServiceSubscriptions::noticeDays(),
            ],
        ];
    }

    /**
     * What one level with these extras comes to: its price, days and revisions, and the client's total with the fee.
     *
     * @param  list<int>  $addonIds
     * @return array{tier: FreelanceServiceTier, addons: \Illuminate\Support\Collection<int, FreelanceServiceAddon>, price_minor: int, delivery_days: int, revisions: int}
     */
    public function pick(Service $s, string $level, array $addonIds): array
    {
        $tier = $s->tiers()->where('level', $level)->first() ?? throw new FreelanceException('service_level_unknown', 422);
        $ids = array_values(array_unique(array_map('intval', $addonIds)));
        $addons = $s->addons()->whereIn('id', $ids)->get();
        if ($addons->count() !== count($ids)) {
            throw new FreelanceException('service_addon_unknown', 422);
        }

        return [
            'tier' => $tier,
            'addons' => $addons,
            'price_minor' => (int) $tier->price_minor + (int) $addons->sum('price_minor'),
            'delivery_days' => (int) $tier->delivery_days + (int) $addons->sum('extra_days'),
            'revisions' => (int) $tier->revisions,
        ];
    }

    /**
     * @param  list<int>  $addonIds
     * @return array<string, mixed>
     */
    public function quote(Service $s, string $level, array $addonIds): array
    {
        $pick = $this->pick($s, $level, $addonIds);
        // The fee is worked out as for any project in this country and specialty (the order becomes one).
        $cost = app(ClientOfferViews::class)->cost(new Project(['country_id' => $s->country_id, 'freelance_specialty_id' => $s->freelance_specialty_id, 'currency_code' => $s->currency_code]), $pick['price_minor']);

        return [
            'level' => $level, 'price_minor' => $cost['price_minor'], 'client_fee_minor' => $cost['client_fee_minor'], 'total_minor' => $cost['total_minor'],
            'delivery_days' => $pick['delivery_days'], 'revisions' => $pick['revisions'], 'addons' => $pick['addons']->pluck('id')->values(),
            'currency' => $s->currency_code, 'decimals' => (int) ($s->country?->currency?->decimal_places ?? 2), 'lines' => $cost['lines'],
        ];
    }

    /** Published, of a verified freelancer, in this country. */
    private function visible(int $countryId): Builder
    {
        return Service::query()->where('country_id', $countryId)->where('status', Service::PUBLISHED)
            ->whereHas('freelancer', fn ($q) => $q->where('status', FreelancerStatus::Verified->value));
    }

    /** @return array<string, mixed> */
    private function freelancer(Service $s, bool $withNumbers): array
    {
        $f = $s->freelancer;
        $out = ['uuid' => $f?->uuid, 'name' => $f?->name, 'headline' => $f?->headline, 'avatar_url' => $f?->getFirstMediaUrl('avatar') ?: null, 'badge' => $this->perks->badge($f), 'skill_badges' => $f === null ? [] : app(\Modules\Freelance\Services\Skills\FreelanceSkillBadges::class)->of($f)];
        if (! $withNumbers || $f === null) {
            return $out;
        }
        $stats = app(FreelanceReviews::class)->stats($f);

        return $out + ['rating' => $stats['rating'], 'reviews_count' => $stats['reviews_count'], 'completed_projects' => $stats['completed_projects'], 'on_time_rate' => $stats['on_time_rate'],
        ];
    }
}
