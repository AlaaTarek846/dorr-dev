<?php

namespace Modules\Rides\Services\Drivers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\Vehicle;
use Modules\Rides\Enums\PricingStrategy;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverService;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Services\Policy\RidePolicies;

/**
 * O-89 (owner): what a captain can work in. The dashboard decides, per country and per kind of vehicle,
 * which services are open (a bus carries riders in Egypt, only runs bus trips in Saudi Arabia); the
 * captain switches on the ones they want among those. Every place that hands out work asks here:
 * dispatch (rides, hourly hire, With Me), scheduled rides, the airport queue, bus trips, the sea,
 * driver on demand and lessons. A country with no rules yet works with {@see self::DEFAULTS}.
 */
class DriverServices
{
    private const CACHE = 'rides:driver-services';

    public const KINDS = ['car', 'motorcycle', 'minibus', 'bus', 'boat', 'yacht', 'none'];

    /** Until the dashboard sets a country: what each kind of vehicle did so far. */
    public const DEFAULTS = [
        'car' => ['rides', 'hourly', 'scheduled', 'airport', 'hire', 'lessons'],
        'motorcycle' => ['rides', 'hourly', 'scheduled'],
        'minibus' => ['rides', 'scheduled', 'bus'],
        'bus' => ['bus'],
        'boat' => ['marine'],
        'yacht' => ['marine'],
        'none' => ['hire', 'lessons'],
    ];

    public function __construct(private readonly RidePolicies $policies) {}

    /** The kind of a body type. */
    public static function kindOfBody(?string $body): string
    {
        return match ($body) {
            null, '' => 'none',
            'sedan', 'suv', 'van' => 'car',
            default => in_array($body, self::KINDS, true) ? $body : 'car',
        };
    }

    /** The captain's kind: their active vehicle, else the latest one, else «none». */
    public function kindOf(Driver $driver): string
    {
        $vehicle = app(DriverEligibility::class)->activeVehicle($driver) ?? $driver->vehicles()->latest('id')->first();

        return $vehicle === null ? 'none' : self::kindOfBody($this->body($vehicle));
    }

    /** @return list<string> the service keys open to this kind of vehicle in this country */
    public function openFor(?int $countryId, string $kind): array
    {
        $services = $this->services();
        if ($countryId === null) {
            return [];
        }
        $rules = $this->rules()->where('country_id', $countryId);
        $keys = $rules->isEmpty()
            ? self::DEFAULTS[$kind] ?? []
            : $rules->where('vehicle_kind', $kind)->where('enabled', true)->pluck('driver_service_id')
                ->map(fn ($id) => $services->firstWhere('id', $id)?->key)->filter()->values()->all();

        return array_values(array_filter($keys, fn (string $k) => ($s = $services->firstWhere('key', $k)) !== null && $s->status));
    }

    /** @return list<string> what this captain's vehicle may do where they work */
    public function available(Driver $driver): array
    {
        return $this->openFor($driver->country_id === null ? null : (int) $driver->country_id, $this->kindOf($driver));
    }

    /** May this captain get this kind of work now: open to their vehicle, not coming soon, and switched on by them? */
    public function offers(Driver $driver, string $key): bool
    {
        if (! in_array($key, $this->available($driver), true)) {
            return false;
        }
        $service = $this->services()->firstWhere('key', $key);

        return ! $service->coming_soon && $this->switchedOn($driver, $service->id);
    }

    /** Why not: `service_not_for_vehicle` (the dashboard didn't open it) or `service_switched_off` (the captain did). */
    public function problem(Driver $driver, string $key): ?string
    {
        if (! in_array($key, $this->available($driver), true) || $this->services()->firstWhere('key', $key)?->coming_soon) {
            return 'service_not_for_vehicle';
        }

        return $this->offers($driver, $key) ? null : 'service_switched_off';
    }

    public function assertOffers(Driver $driver, string $key): void
    {
        if (($problem = $this->problem($driver, $key)) !== null) {
            throw new RidesException($problem, 403);
        }
    }

    /**
     * The service a ride category belongs to in this country: bus trips (seats), the sea (time),
     * hourly hire (by the hour only here), or passenger rides (With Me is one of its categories).
     */
    public function serviceOfCategory(RideCategory $category, ?int $countryId): string
    {
        return match (true) {
            $category->slug === 'bus' || $category->pricing_strategy === PricingStrategy::Seat => 'bus',
            $category->slug === 'marine' || $category->pricing_strategy === PricingStrategy::Time => 'marine',
            $this->hourlyOnly($category, $countryId) => 'hourly',
            default => 'rides',
        };
    }

    /**
     * O-86 per country: `hourly.mode` in the rules for this country (and category) — «hourly», «distance»,
     * or «category» (follow the category's own `hourly_only` switch).
     */
    public function hourlyOnly(RideCategory $category, ?int $countryId, ?int $cityId = null): bool
    {
        $mode = $countryId === null ? 'category' : (string) ($this->policies->for($countryId, $cityId, null, $category->id)->get('hourly.mode') ?: 'category');

        return match ($mode) {
            'hourly' => true,
            'distance' => false,
            default => $category->flag('hourly_only'),
        };
    }

    /**
     * What the captain's app shows: each service open to their vehicle, drawn, with their switch.
     *
     * @return array{kind: string, services: list<array<string, mixed>>}
     */
    public function present(Driver $driver): array
    {
        $open = $this->available($driver);
        $choices = DB::table('driver_service_choices')->where('driver_id', $driver->id)->pluck('enabled', 'driver_service_id');

        return [
            'kind' => $this->kindOf($driver),
            'services' => $this->services()->filter(fn (DriverService $s) => in_array($s->key, $open, true))->sortBy('sort_order')
                ->map(fn (DriverService $s) => [
                    'key' => $s->key,
                    'name' => $s->localized('name'),
                    'description' => $s->localized('description'),
                    'icon' => $s->icon,
                    'color' => $s->color,
                    'coming_soon' => $s->coming_soon,
                    'on' => ! $s->coming_soon && (bool) ($choices[$s->id] ?? true),
                ])->values()->all(),
        ];
    }

    /** The captain switches a service on or off — only one open to their vehicle, and not one coming soon. */
    public function choose(Driver $driver, string $key, bool $on): array
    {
        $service = $this->services()->firstWhere('key', $key);
        if ($service === null || ! in_array($key, $this->available($driver), true)) {
            throw new RidesException('service_not_for_vehicle', 403);
        }
        if ($service->coming_soon) {
            throw new RidesException('service_coming_soon', 422);
        }
        DB::table('driver_service_choices')->updateOrInsert(
            ['driver_id' => $driver->id, 'driver_service_id' => $service->id],
            ['enabled' => $on, 'created_at' => now(), 'updated_at' => now()],
        );

        return $this->present($driver);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE.':services');
        Cache::forget(self::CACHE.':rules');
    }

    private function switchedOn(Driver $driver, int $serviceId): bool
    {
        $choice = DB::table('driver_service_choices')->where('driver_id', $driver->id)->where('driver_service_id', $serviceId)->value('enabled');

        return $choice === null || (bool) $choice;
    }

    /** @return Collection<int, DriverService> */
    private function services(): Collection
    {
        return Cache::remember(self::CACHE.':services', 300, fn () => DriverService::query()->orderBy('sort_order')->get());
    }

    /** @return Collection<int, object> */
    private function rules(): Collection
    {
        return Cache::remember(self::CACHE.':rules', 300, fn () => DB::table('driver_service_rules')->get(['driver_service_id', 'country_id', 'vehicle_kind', 'enabled'])
            ->map(fn ($r) => (object) ['driver_service_id' => (int) $r->driver_service_id, 'country_id' => (int) $r->country_id, 'vehicle_kind' => $r->vehicle_kind, 'enabled' => (bool) $r->enabled]));
    }

    private function body(Vehicle $vehicle): ?string
    {
        return $vehicle->body_type instanceof \BackedEnum ? $vehicle->body_type->value : $vehicle->body_type;
    }
}
