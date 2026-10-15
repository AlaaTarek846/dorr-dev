<?php

namespace Modules\Rides\Services\Bidding;

use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Pricing\FareCalculator;

/**
 * The numbers of bidding (docs/rides-plan.md §9.1, §9.4): the rider may go under the suggested price
 * by `bids.rider_max_discount_bp` at most (never under the category's minimum fare); a driver may
 * ask up to `bids.counter_cap_bp` over the rider's price. An agreed price is a fixed fare: the
 * commission and the tax come out of it like any upfront ride.
 */
final class BidPricing
{
    /** @param array<string, mixed> $policy */
    public static function floor(array $policy, int $suggested): int
    {
        $floor = $suggested - intdiv($suggested * (int) ($policy['bids.rider_max_discount_bp'] ?? 2000), 10000);
        // Whole currency units, rounded up (a halala floor reads badly on the − button).
        $floor = (int) ceil($floor / 100) * 100;

        return max($floor, (int) ($policy['price.min_fare_minor'] ?? 0));
    }

    /** @param array<string, mixed> $policy */
    public static function cap(array $policy, int $riderPrice): int
    {
        return $riderPrice + intdiv($riderPrice * (int) ($policy['bids.counter_cap_bp'] ?? 5000), 10000);
    }

    /**
     * The breakdown of a ride agreed at `$price` (what the rider pays for the trip, before an old debt).
     *
     * @return array{fare: int, commission: int, tax: int, driver_net: int, total: int}
     */
    public static function breakdown(Ride $ride, int $price): array
    {
        $policy = (array) $ride->snapshot('policy', []);
        $b = app(FareCalculator::class)->compute(
            FareCalculator::priceOf($policy), (int) $ride->planned_distance_m, (int) $ride->planned_duration_s,
            $ride->snapshot('tax'), 0, 0, (int) $ride->previous_balance_minor, $price,
        );

        return ['fare' => $b->fare, 'commission' => $b->commission, 'tax' => $b->tax, 'driver_net' => $b->driverNet, 'total' => $b->total];
    }

    public static function driverNet(Ride $ride, int $price): int
    {
        return self::breakdown($ride, $price)['driver_net'];
    }
}
