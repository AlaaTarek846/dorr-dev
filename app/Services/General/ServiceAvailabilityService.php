<?php

namespace App\Services\General;

use App\Models\Country;
use App\Models\Region;
use App\Models\ServiceAvailability;
use App\Models\ServiceCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Who can use a service where — one place for the dashboard (the switches) and, later, the apps (what is allowed).
 *
 * Every service has three switches per country, and per region of that country:
 *  - service:  the service as a whole. Off in a place, and neither customers nor providers can use it there;
 *  - customer: the customer app. Off, the customer sees it hidden, "coming soon" or without a way to order (`customer_mode`);
 *  - provider: the provider app (only services that have providers). One switch for now — registration, profile,
 *    documents, review and being ready to work all follow it; stages can be split out later without touching the rest.
 * The customer and provider switches are independent of each other.
 *
 * A region's switch that is not set follows its country's; a country's switch that is not set is on. A service the country
 * does not carry (Countries → services) is off everywhere in it.
 */
class ServiceAvailabilityService
{
    public const DEFAULTS = [
        'service_enabled' => true,
        'customer_enabled' => true,
        'customer_mode' => ServiceAvailability::CUSTOMER_HIDDEN,
        'provider_enabled' => true,
    ];

    /**
     * What is allowed for one service in a place (a country, or one of its regions).
     *
     * @return array{carried: bool, has_provider: bool, service_open: bool, customer_state: string, customer_visible: bool, customer_can_order: bool, provider_can_use: bool}
     */
    public function resolve(ServiceCategory $service, int $countryId, ?int $regionId = null): array
    {
        $carried = $service->countries()->whereKey($countryId)->exists();

        return $this->effective(
            $service,
            $carried,
            $this->rowFor($service->id, $countryId, null),
            $regionId !== null ? $this->rowFor($service->id, $countryId, $regionId) : null,
        )['effective'];
    }

    /**
     * One page of the services of a country with their switches, for the dashboard — found by name in any language when
     * `$search` is given. With a region, each switch says whether the region sets it itself (`overridden`) or follows
     * the country.
     */
    public function listFor(Country $country, ?Region $region = null, ?string $search = null, int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $countryRows = ServiceAvailability::query()->where('country_id', $country->id)->whereNull('region_id')->get()->keyBy('service_category_id');
        $regionRows = $region
            ? ServiceAvailability::query()->where('country_id', $country->id)->where('region_id', $region->id)->get()->keyBy('service_category_id')
            : collect();

        $term = trim((string) $search);

        $page = $country->serviceCategories()
            ->where('service_categories.status', true)
            ->where(fn ($q) => $q->whereNull('module_name')->orWhereNotIn('module_name', ServiceCategory::ADMIN_ONLY_MODULES))
            ->whereDoesntHave('children')
            // The name in any of its languages; "!" is the LIKE escape, so %, _ and ! typed are plain characters.
            ->when($term !== '', fn ($q) => $q->whereHas('translations', fn ($t) => $t->whereRaw("name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%'])))
            ->with(['translations', 'translation'])
            ->orderBy('sort_order')->orderBy('service_categories.id')
            ->paginate($perPage, ['service_categories.*'], 'page', $page);

        return $page->through(fn (ServiceCategory $service) => $this->item($service, $countryRows->get($service->id), $regionRows->get($service->id), $region !== null));
    }

    /**
     * Sets some of a service's switches in a place. In a region a null clears that switch (it follows the country again).
     *
     * @param  array<string, mixed>  $changes  any of ServiceAvailability::SWITCHES
     * @return array<string, mixed> the service as listFor() shows it
     */
    public function update(Country $country, ?Region $region, ServiceCategory $service, array $changes): array
    {
        $this->assertCarried($country, $service);

        $changes = array_intersect_key($changes, array_flip(ServiceAvailability::SWITCHES));

        if (($changes['provider_enabled'] ?? null) !== null && ! $service->hasAudience('provider')) {
            throw ValidationException::withMessages(['provider_enabled' => __('api.service_has_no_provider')]);
        }

        if ($region === null && in_array(null, $changes, true)) {
            // A country always says yes or no; "not set" is the same as on, so it is not offered there.
            throw ValidationException::withMessages(['value' => __('api.service_switch_required')]);
        }

        $row = ServiceAvailability::query()->firstOrNew([
            'service_category_id' => $service->id,
            'country_id' => $country->id,
            'region_id' => $region?->id,
        ]);
        $row->fill($changes)->save();

        // A region row that no longer sets anything is the same as no row.
        if ($region !== null && collect(ServiceAvailability::SWITCHES)->every(fn ($switch) => $row->{$switch} === null)) {
            $row->delete();
        }

        return $this->listItem($country, $region, $service);
    }

    /** A region goes back to following its country for one service. */
    public function reset(Country $country, Region $region, ServiceCategory $service): array
    {
        $this->assertCarried($country, $service);

        ServiceAvailability::query()
            ->where('service_category_id', $service->id)->where('country_id', $country->id)->where('region_id', $region->id)
            ->delete();

        return $this->listItem($country, $region, $service);
    }

    /** @return array<string, mixed> */
    private function listItem(Country $country, ?Region $region, ServiceCategory $service): array
    {
        $service->loadMissing(['translations', 'translation']);

        return $this->item(
            $service,
            $this->rowFor($service->id, $country->id, null),
            $region ? $this->rowFor($service->id, $country->id, $region->id) : null,
            $region !== null,
        );
    }

    private function assertCarried(Country $country, ServiceCategory $service): void
    {
        if (! $country->serviceCategories()->whereKey($service->id)->exists()) {
            throw ValidationException::withMessages(['service_category_id' => __('api.service_not_in_country')]);
        }
    }

    private function rowFor(int $serviceId, int $countryId, ?int $regionId): ?ServiceAvailability
    {
        return ServiceAvailability::query()
            ->where('service_category_id', $serviceId)->where('country_id', $countryId)
            ->when($regionId === null, fn ($q) => $q->whereNull('region_id'), fn ($q) => $q->where('region_id', $regionId))
            ->first();
    }

    /** @return array<string, mixed> */
    private function item(ServiceCategory $service, ?ServiceAvailability $countryRow, ?ServiceAvailability $regionRow, bool $inRegion): array
    {
        $resolved = $this->effective($service, true, $countryRow, $regionRow);

        return [
            'id' => $service->id,
            'name' => $service->translatedName(),
            'module_name' => $service->module_name,
            'has_provider' => $resolved['effective']['has_provider'],
            'switches' => $resolved['switches'],
            // In a region: which switches the region sets itself (the others follow the country).
            'overridden' => $inRegion
                ? collect(ServiceAvailability::SWITCHES)->mapWithKeys(fn ($s) => [$s => $regionRow?->{$s} !== null])->all()
                : null,
            'effective' => $resolved['effective'],
        ];
    }

    /**
     * @return array{switches: array<string, mixed>, effective: array<string, mixed>}
     */
    private function effective(ServiceCategory $service, bool $carried, ?ServiceAvailability $countryRow, ?ServiceAvailability $regionRow): array
    {
        $switches = [];

        foreach (ServiceAvailability::SWITCHES as $switch) {
            $switches[$switch] = $regionRow?->{$switch} ?? $countryRow?->{$switch} ?? self::DEFAULTS[$switch];
        }

        $hasProvider = $service->hasAudience('provider');
        $serviceOpen = $carried && $switches['service_enabled'];
        $customerOn = $serviceOpen && $switches['customer_enabled'];

        // A service closed in the place is simply not there for the customer; a closed customer switch follows its mode.
        $state = $customerOn ? 'enabled' : ($serviceOpen ? $switches['customer_mode'] : ServiceAvailability::CUSTOMER_HIDDEN);

        return [
            'switches' => $switches,
            'effective' => [
                'carried' => $carried,
                'has_provider' => $hasProvider,
                'service_open' => $serviceOpen,
                'customer_state' => $state,
                'customer_visible' => $state !== ServiceAvailability::CUSTOMER_HIDDEN,
                'customer_can_order' => $customerOn,
                'provider_can_use' => $serviceOpen && $hasProvider && $switches['provider_enabled'],
            ],
        ];
    }
}
