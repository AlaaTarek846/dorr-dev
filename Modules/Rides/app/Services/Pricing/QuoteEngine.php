<?php

namespace Modules\Rides\Services\Pricing;

use App\Models\Country;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Rides\Enums\PricingMode;
use Modules\Rides\Enums\PricingStrategy;
use Modules\Rides\Enums\ZoneType;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideQuote;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Services\Bidding\BidPricing;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Geo\GeoProvider;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;

/**
 * Prices a trip for every category open where it starts (docs/rides-plan.md §7.1): one route call
 * for all of them, a saved quote per category with its lines, the amount to hold, a fingerprint and
 * an expiry. Upfront: a fixed total. Metered: an estimate range. Open destination: the rate card.
 */
class QuoteEngine
{
    /** The metered estimate shows ±15% around the route price. */
    private const ESTIMATE_SPREAD_BP = 1500;

    public function __construct(
        private readonly GeoProvider $geo,
        private readonly CityResolver $cities,
        private readonly RideFeatures $features,
        private readonly RidePolicies $policies,
        private readonly TaxEngine $taxes,
        private readonly FareCalculator $calculator,
        private readonly RidePromotions $promotions,
    ) {}

    /**
     * @param  array{origin: array{lat: float, lng: float, address?: ?string}, destination?: ?array{lat: float, lng: float, address?: ?string}, stops?: list<array{lat: float, lng: float, address?: ?string}>, category?: ?string, coupon_code?: ?string}  $trip
     * @return Collection<int, RideQuote>
     */
    public function quote(User $rider, array $trip): Collection
    {
        $origin = $trip['origin'];
        $destination = $trip['destination'] ?? null;
        $stops = array_values($trip['stops'] ?? []);
        $open = $destination === null;

        $cityId = $this->cities->cityAt((float) $origin['lat'], (float) $origin['lng']);
        $countryId = ($cityId !== null ? $this->cities->countryOf($cityId) : null) ?? $rider->country_id;
        $country = $countryId !== null ? Country::query()->find($countryId) : null;
        if ($country === null || ! $this->features->allows($country->id, $cityId, RideFeatures::SERVICE)) {
            throw RidesException::featureDisabled(RideFeatures::SERVICE);
        }
        if ($open && ! $this->features->allows($country->id, $cityId, 'open_destination')) {
            throw RidesException::featureDisabled('open_destination');
        }
        if ($stops !== [] && ! $this->features->allows($country->id, $cityId, 'multi_stop')) {
            throw RidesException::featureDisabled('multi_stop');
        }
        foreach (array_filter([$origin, $destination, ...$stops]) as $point) {
            if ($this->isBlocked($country->id, (float) $point['lat'], (float) $point['lng'])) {
                throw new RidesException('blocked_area', 422);
            }
        }

        // R-45: a coupon is priced into the quote (its own line), switched on per place.
        $coupon = null;
        if (($code = trim((string) ($trip['coupon_code'] ?? ''))) !== '') {
            if (! $this->features->allows($country->id, $cityId, 'coupons')) {
                throw RidesException::featureDisabled('coupons');
            }
            if ($open) {
                throw new RidesException('coupon_needs_destination', 422);
            }
            $coupon = RidePromotions::snapshot($this->promotions->find($rider, $country->id, $code));
        }

        $route = $open ? null : $this->geo->route(
            (float) $origin['lat'], (float) $origin['lng'], (float) $destination['lat'], (float) $destination['lng'],
            array_map(fn (array $s) => [(float) $s['lat'], (float) $s['lng']], $stops),
        );
        $tax = $this->taxes->ruleFor($country->id);

        $categories = RideCategory::query()->where('status', true)
            ->whereIn('pricing_strategy', [PricingStrategy::Distance->value, PricingStrategy::TripFlat->value, PricingStrategy::Bid->value])
            ->when($trip['category'] ?? null, fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('sort_order')->get()
            ->filter(fn (RideCategory $c) => $this->features->allows($country->id, $cityId, RideFeatures::categoryFeature($c->slug)))
            ->filter(fn (RideCategory $c) => ! $open || $c->flag('open_destination_allowed'))
            ->filter(fn (RideCategory $c) => $stops === [] || $c->flag('multi_stop_allowed'))
            // Bidding (§9) needs a destination to price, and its own switch.
            ->filter(fn (RideCategory $c) => $c->pricing_strategy !== PricingStrategy::Bid || (! $open && $this->features->allows($country->id, $cityId, 'bidding')));

        if ($categories->isEmpty()) {
            throw new RidesException('no_category_here', 422);
        }

        $quotes = $categories->values()->map(function (RideCategory $category) use ($rider, $country, $cityId, $origin, $destination, $stops, $open, $route, $tax, $coupon) {
            $policy = $this->policies->for($country->id, $cityId, null, $category->id);
            if ($stops !== [] && count($stops) > $policy->int('stops.max')) {
                throw new RidesException('too_many_stops', 422, ['max' => $policy->int('stops.max')]);
            }
            $bidding = $category->pricing_strategy === PricingStrategy::Bid;
            // An agreed price is always fixed (§9.2.7).
            $mode = $open ? PricingMode::Metered : ($bidding ? PricingMode::Upfront : $policy->pricingMode());
            $price = FareCalculator::priceOf($policy->all());
            // A category nobody priced yet is never offered — a forgotten price must not mean a free ride.
            if ((int) $price['min_fare_minor'] <= 0 && (int) $price['base_minor'] + (int) $price['per_km_minor'] + (int) $price['per_min_minor'] <= 0) {
                return null;
            }

            // DORR's service fee and the coupon (R-45, R-47) — not on an agreed (bidding) price.
            $customerFee = ['minor' => $policy->int('price.customer_fee_minor'), 'bp' => $policy->int('price.customer_fee_bp')];
            $extras = $bidding ? [] : array_filter([
                'customer_fee' => $customerFee['minor'] + $customerFee['bp'] > 0 ? $customerFee : null,
                'coupon' => $coupon,
            ]);
            $breakdown = $route === null ? null : $this->calculator->compute($price, $route->distanceM, $route->durationS, $tax);
            if ($breakdown !== null && $extras !== []) {
                $breakdown = PriceExtras::apply($breakdown, $extras);
            }
            [$total, $min, $max, $hold] = match (true) {
                $breakdown === null => [null, null, null, $policy->int('open_destination.hold_minor')],
                $mode === PricingMode::Upfront => [$breakdown->total, null, null, $breakdown->total],
                default => [null, self::spread($breakdown->total, -1), self::spread($breakdown->total, 1), self::spread($breakdown->total, 1)],
            };
            $lines = $breakdown?->lines ?? [['code' => 'rate_card', 'amount_minor' => 0, 'visible_to' => 'both', 'meta' => [
                'base_minor' => (int) $price['base_minor'], 'per_km_minor' => (int) $price['per_km_minor'],
                'per_min_minor' => (int) $price['per_min_minor'], 'min_fare_minor' => (int) $price['min_fare_minor'],
            ]]];

            $fingerprintOf = [$category->id, $mode->value, $lines, $total, $min, $max, $hold, $origin, $destination, $stops];

            return RideQuote::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $rider->id,
                'country_id' => $country->id,
                'city_id' => $cityId,
                'currency_id' => $country->currency_id,
                'category_id' => $category->id,
                'pricing_mode' => $mode,
                'origin_lat' => $origin['lat'],
                'origin_lng' => $origin['lng'],
                'origin_address' => $origin['address'] ?? null,
                'destination_lat' => $destination['lat'] ?? null,
                'destination_lng' => $destination['lng'] ?? null,
                'destination_address' => $destination['address'] ?? null,
                'stops' => $stops === [] ? null : $stops,
                'distance_m' => $route?->distanceM,
                'duration_s' => $route?->durationS,
                'route_estimated' => (bool) $route?->estimated,
                'polyline' => $route?->polyline,
                'lines' => $lines,
                'total_minor' => $total,
                'estimate_min_minor' => $min,
                'estimate_max_minor' => $max,
                'hold_minor' => $hold,
                'driver_net_minor' => $mode === PricingMode::Upfront ? $breakdown?->driverNet : null,
                'fingerprint' => hash('sha256', (string) json_encode($fingerprintOf)),
                'policy_snapshot' => [
                    'policy' => $policy->all(), 'policy_ids' => $policy->policyIds, 'tax' => $tax,
                    // The coupon only stays when it gave something here (a minimum it didn't reach = no coupon).
                    'extras' => ($breakdown?->discount ?? 0) > 0 ? $extras : array_diff_key($extras, ['coupon' => true]),
                    'coupon' => $coupon === null ? null : [
                        'code' => $coupon['code'],
                        'discount_minor' => (int) ($breakdown?->discount ?? 0),
                        'reason' => $bidding ? 'coupon_not_for_bidding' : (($breakdown?->discount ?? 0) > 0 ? null : 'coupon_min_amount'),
                    ],
                    // R-48: each method's fee on what this price charges; the chosen one joins the lines at confirmation.
                    'payment_fees' => $breakdown === null || $bidding ? null : self::paymentFees($policy->all(), (int) ($max ?? $total)),
                ] + ($bidding && $total !== null ? [
                    'bid' => ['suggested' => $total, 'floor' => BidPricing::floor($policy->all(), $total)],
                ] : []),
                'expires_at' => now()->addSeconds($policy->int('quote_ttl_s')),
            ])->setRelation('category', $category);
        })->filter()->values();

        if ($quotes->isEmpty()) {
            throw new RidesException('no_category_here', 422);
        }

        return $quotes;
    }

    /**
     * R-48: what each way of paying adds on an amount (the wallet never adds anything).
     *
     * @param  array<string, mixed>  $policy
     * @return array{wallet: int, card: int, cash: int}
     */
    public static function paymentFees(array $policy, int $amount): array
    {
        return [
            'wallet' => 0,
            'card' => PriceExtras::paymentFee((int) ($policy['payment.card_fee_minor'] ?? 0), (int) ($policy['payment.card_fee_bp'] ?? 0), $amount),
            'cash' => PriceExtras::paymentFee((int) ($policy['payment.cash_fee_minor'] ?? 0), 0, $amount),
        ];
    }

    private function isBlocked(int $countryId, float $lat, float $lng): bool
    {
        return RideZone::query()
            ->where('country_id', $countryId)->where('type', ZoneType::Blocked->value)->where('status', true)
            ->where('min_lat', '<=', $lat)->where('max_lat', '>=', $lat)
            ->where('min_lng', '<=', $lng)->where('max_lng', '>=', $lng)
            ->get()
            ->contains(fn (RideZone $zone) => $zone->contains($lat, $lng));
    }

    /** ±15% around a price, rounded to whole currency units (100 minor). */
    private static function spread(int $total, int $direction): int
    {
        $value = $total + $direction * intdiv($total * self::ESTIMATE_SPREAD_BP, 10000);

        return $direction < 0 ? intdiv($value, 100) * 100 : (int) ceil($value / 100) * 100;
    }
}
