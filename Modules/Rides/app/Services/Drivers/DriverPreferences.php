<?php

namespace Modules\Rides\Services\Drivers;

use App\Models\Country;
use Illuminate\Support\Collection;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverPreference;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;

/**
 * What a captain wants to be asked (docs/rides_tasks.md D-32..D-37): which categories, how far they
 * will go for a pickup, and Auto-Accept — requests that fit their own rules are taken for them.
 * Categories and the pickup range narrow who is asked (see DispatchEngine::candidates). Auto-Accept
 * exists only where its switch is on, and its price and payment filters only where the country's
 * policy allows them (`auto_accept.price_filter`, `auto_accept.payment_filter`) — "when allowed".
 */
class DriverPreferences
{
    public function __construct(
        private readonly DriverEligibility $eligibility,
        private readonly RideFeatures $features,
        private readonly RidePolicies $policies,
    ) {}

    /** @return array<string, mixed> */
    public function show(Driver $driver): array
    {
        $pref = DriverPreference::query()->find($driver->id);
        $countryId = $driver->country_id === null ? null : (int) $driver->country_id;
        $policy = $countryId === null ? null : $this->policies->for($countryId);
        $auto = (array) ($pref?->auto_accept ?? []);
        $available = $countryId !== null && $this->features->allows($countryId, null, 'auto_accept');
        $priceAllowed = $available && (bool) $policy?->bool('auto_accept.price_filter');
        $paymentAllowed = $available && (bool) $policy?->bool('auto_accept.payment_filter');
        $chosen = $pref?->categories;
        $currency = $countryId === null ? null : Country::query()->find($countryId)?->currency;

        return [
            // For the money fields (minimum fare, per km): the amounts are in minor units of this currency.
            'currency' => $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) ($currency->decimal_places ?? 2)],
            'categories' => $this->categories($driver)->map(fn (array $c) => [
                'slug' => $c['slug'],
                'name' => $c['name'],
                'eligible' => $c['eligible'],
                'selected' => $c['eligible'] && (! is_array($chosen) || in_array($c['slug'], $chosen, true)),
            ])->values()->all(),
            'pickup_radius_m' => $pref?->pickup_radius_m,
            'pickup_radius_max_m' => $policy?->int('search.max_radius_m'),
            // R-235, R-236, D-390: what the car has / takes — shown only where the add-on is switched on.
            'equipment' => [
                'pet_friendly' => (bool) ($pref?->pet_friendly ?? false),
                'child_seats' => (int) ($pref?->child_seats ?? 0),
                'pets_offered' => $countryId !== null && $this->features->allows($countryId, null, 'pet'),
                'child_seats_offered' => $countryId !== null && $this->features->allows($countryId, null, 'child_seat'),
            ],
            'auto_accept' => [
                'available' => $available,
                'enabled' => $available && ! empty($auto['enabled']),
                'max_pickup_m' => $auto['max_pickup_m'] ?? null,
                'price_filter' => [
                    'allowed' => $priceAllowed,
                    'min_fare_minor' => $priceAllowed ? ($auto['min_fare_minor'] ?? null) : null,
                    'min_per_km_minor' => $priceAllowed ? ($auto['min_per_km_minor'] ?? null) : null,
                ],
                'payment_filter' => [
                    'allowed' => $paymentAllowed,
                    'methods' => $paymentAllowed ? array_values((array) ($auto['payment_methods'] ?? [])) : [],
                    'options' => array_map(fn (RidePaymentMethod $m) => $m->value, RidePaymentMethod::cases()),
                ],
            ],
        ];
    }

    /**
     * Saves what was sent (the rest stays); absent keys are left as they are.
     *
     * @param  array<string, mixed>  $input  validated by the controller
     * @return array<string, mixed>
     */
    public function save(Driver $driver, array $input): array
    {
        $countryId = $driver->country_id === null ? null : (int) $driver->country_id;
        $policy = $countryId === null ? null : $this->policies->for($countryId);
        $pref = DriverPreference::query()->firstOrNew(['driver_id' => $driver->id]);

        if (array_key_exists('categories', $input)) {
            $eligible = $this->categories($driver)->where('eligible', true)->pluck('slug')->all();
            $wanted = $input['categories'];
            if ($wanted === null) {
                $pref->categories = null;
            } else {
                $wanted = array_values(array_unique((array) $wanted));
                if ($wanted === [] || array_diff($wanted, $eligible) !== []) {
                    throw new RidesException($wanted === [] ? 'preferences_no_category' : 'preferences_category_unavailable');
                }
                // Every category they can take picked = no narrowing at all.
                $pref->categories = count(array_diff($eligible, $wanted)) === 0 ? null : $wanted;
            }
        }

        if (array_key_exists('pickup_radius_m', $input)) {
            $radius = $input['pickup_radius_m'];
            if ($radius !== null && $policy !== null && (int) $radius > $policy->int('search.max_radius_m')) {
                throw new RidesException('preferences_radius_too_far', 422, ['km' => intdiv($policy->int('search.max_radius_m'), 1000)]);
            }
            $pref->pickup_radius_m = $radius === null ? null : (int) $radius;
        }

        if (array_key_exists('pet_friendly', $input)) {
            $pref->pet_friendly = (bool) $input['pet_friendly'];
        }
        if (array_key_exists('child_seats', $input)) {
            $pref->child_seats = max(0, min(3, (int) $input['child_seats']));
        }

        if (array_key_exists('auto_accept', $input)) {
            $pref->auto_accept = $this->autoAccept((array) $pref->auto_accept, (array) $input['auto_accept'], $countryId, $policy?->bool('auto_accept.price_filter') ?? false, $policy?->bool('auto_accept.payment_filter') ?? false);
        }

        $pref->save();

        return $this->show($driver);
    }

    /**
     * Would this request be taken for the captain (D-34..D-37)? Their own rules, and only inside what
     * the ride's country / city and policy allow.
     */
    public function autoAccepts(Ride $ride, Driver $driver, float $pickupDistanceM, int $driverNetMinor): bool
    {
        $auto = (array) (DriverPreference::query()->find($driver->id)?->auto_accept ?? []);
        if (empty($auto['enabled']) || ! $this->features->allows((int) $ride->country_id, $ride->city_id === null ? null : (int) $ride->city_id, 'auto_accept')) {
            return false;
        }
        if (isset($auto['max_pickup_m']) && $pickupDistanceM > (int) $auto['max_pickup_m']) {
            return false;
        }
        if ($ride->policy('auto_accept.price_filter', false)) {
            if (isset($auto['min_fare_minor']) && $driverNetMinor < (int) $auto['min_fare_minor']) {
                return false;
            }
            // Net per km of the trip, in minor units: net / (metres / 1000) ≥ min  ⇔  net × 1000 ≥ min × metres.
            if (isset($auto['min_per_km_minor']) && (int) $ride->planned_distance_m > 0 && $driverNetMinor * 1000 < (int) $auto['min_per_km_minor'] * (int) $ride->planned_distance_m) {
                return false;
            }
        }
        if ($ride->policy('auto_accept.payment_filter', false) && ! empty($auto['payment_methods']) && ! in_array($ride->payment_method->value, (array) $auto['payment_methods'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function autoAccept(array $current, array $input, ?int $countryId, bool $priceAllowed, bool $paymentAllowed): array
    {
        $available = $countryId !== null && $this->features->allows($countryId, null, 'auto_accept');
        if (! empty($input['enabled']) && ! $available) {
            throw RidesException::featureDisabled('auto_accept');
        }
        $price = ['min_fare_minor', 'min_per_km_minor'];
        foreach ($price as $key) {
            if (($input[$key] ?? null) !== null && ! $priceAllowed) {
                throw new RidesException('auto_accept_filter_not_allowed');
            }
        }
        if (! empty($input['payment_methods']) && ! $paymentAllowed) {
            throw new RidesException('auto_accept_filter_not_allowed');
        }

        $next = $current;
        foreach (['enabled', 'max_pickup_m', 'min_fare_minor', 'min_per_km_minor', 'payment_methods'] as $key) {
            if (array_key_exists($key, $input)) {
                $next[$key] = $input[$key];
            }
        }
        $next['enabled'] = (bool) ($next['enabled'] ?? false);
        $next['payment_methods'] = array_values(array_unique((array) ($next['payment_methods'] ?? [])));

        return $next;
    }

    /** @return Collection<int, array{slug: string, name: string|null, eligible: bool}> */
    private function categories(Driver $driver)
    {
        $countryId = $driver->country_id === null ? null : (int) $driver->country_id;
        $vehicle = $this->eligibility->activeVehicle($driver);

        return RideCategory::query()->with('translations')->where('status', true)->orderBy('sort_order')->get()
            ->filter(fn (RideCategory $c) => $countryId !== null && $this->features->allows($countryId, null, RideFeatures::categoryFeature($c->slug)))
            ->map(fn (RideCategory $c) => [
                'slug' => $c->slug,
                'name' => $c->translated('name'),
                'eligible' => $vehicle !== null && $this->eligibility->categoryProblem($driver, $vehicle, $c) === null,
            ])->values();
    }
}
