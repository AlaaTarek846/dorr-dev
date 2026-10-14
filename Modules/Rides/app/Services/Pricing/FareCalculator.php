<?php

namespace Modules\Rides\Services\Pricing;

use Modules\Rides\Enums\TaxBase;
use Modules\Rides\Enums\TaxBearer;

/**
 * Jawad's formula, generalised (docs/rides-plan.md §7.2–7.3), in minor units with no floats kept:
 *
 *   fare        = max(base + per_km × km + per_min × min, min_fare)   (or a fixed agreed fare)
 *   waiting     = whole minutes past the free wait × per-minute price (pickup and stops)
 *   commission  = fare × commission% (or a fixed amount)              — on the fare only, like Jawad
 *   tax         = rate × its base (Saudi Arabia: the commission)
 *   rider pays  = fare + waiting (+ tax when the rider carries it) + an old debt collected in cash
 *   driver nets = fare + waiting − commission (− tax when the driver carries it — Saudi Arabia)
 */
class FareCalculator
{
    /**
     * @param  array<string, mixed>  $price  the policy's `price.*` values (keys without the prefix)
     * @param  array{base: string, rate_bp: int, borne_by: string}|null  $tax
     */
    public function compute(
        array $price,
        int $distanceM,
        int $durationS,
        ?array $tax,
        int $chargeableWaitS = 0,
        int $chargeableStopsWaitS = 0,
        int $previousBalance = 0,
        ?int $fixedFare = null,
    ): FareBreakdown {
        $distancePart = (int) round(((int) $price['per_km_minor']) * $distanceM / 1000);
        $timePart = (int) round(((int) $price['per_min_minor']) * $durationS / 60);
        $fare = $fixedFare ?? max((int) $price['base_minor'] + $distancePart + $timePart, (int) $price['min_fare_minor']);

        $waiting = intdiv(max(0, $chargeableWaitS), 60) * (int) $price['waiting_per_min_minor'];
        $stopsWait = intdiv(max(0, $chargeableStopsWaitS), 60) * (int) $price['stop_wait_per_min_minor'];

        $commission = $price['commission_type'] === 'fixed'
            ? min((int) $price['commission_value'], $fare)
            : (int) round($fare * (int) $price['commission_value'] / 10000);

        $taxAmount = 0;
        $riderTax = false;
        if ($tax !== null) {
            $base = match (TaxBase::from($tax['base'])) {
                TaxBase::Commission => $commission,
                TaxBase::Fare => $fare,
                TaxBase::FareAndFees => $fare + $waiting + $stopsWait,
            };
            $taxAmount = (int) round($base * (int) $tax['rate_bp'] / 10000);
            $riderTax = TaxBearer::from($tax['borne_by']) === TaxBearer::Rider;
        }

        $earned = $fare + $waiting + $stopsWait;
        $total = $earned + ($riderTax ? $taxAmount : 0) + $previousBalance;
        $driverNet = $earned - $commission - ($riderTax ? 0 : $taxAmount);

        $lines = [
            ['code' => 'fare', 'amount_minor' => $fare, 'visible_to' => 'both', 'meta' => [
                'distance_m' => $distanceM, 'duration_s' => $durationS, 'fixed' => $fixedFare !== null,
                'base_minor' => (int) $price['base_minor'], 'per_km_minor' => (int) $price['per_km_minor'],
                'per_min_minor' => (int) $price['per_min_minor'], 'min_fare_minor' => (int) $price['min_fare_minor'],
            ]],
        ];
        if ($waiting > 0) {
            $lines[] = ['code' => 'waiting', 'amount_minor' => $waiting, 'visible_to' => 'both', 'meta' => ['seconds' => $chargeableWaitS]];
        }
        if ($stopsWait > 0) {
            $lines[] = ['code' => 'stops_wait', 'amount_minor' => $stopsWait, 'visible_to' => 'both', 'meta' => ['seconds' => $chargeableStopsWaitS]];
        }
        if ($tax !== null && $taxAmount > 0) {
            $lines[] = ['code' => 'tax', 'amount_minor' => $taxAmount, 'visible_to' => $riderTax ? 'both' : 'driver', 'meta' => ['base' => $tax['base'], 'rate_bp' => (int) $tax['rate_bp'], 'borne_by' => $tax['borne_by']]];
        }
        if ($previousBalance > 0) {
            $lines[] = ['code' => 'previous_balance', 'amount_minor' => $previousBalance, 'visible_to' => 'both'];
        }
        $lines[] = ['code' => 'commission', 'amount_minor' => $commission, 'visible_to' => 'driver', 'meta' => ['type' => $price['commission_type'], 'value' => (int) $price['commission_value']]];
        $lines[] = ['code' => 'driver_net', 'amount_minor' => $driverNet, 'visible_to' => 'driver'];
        $lines[] = ['code' => 'total', 'amount_minor' => $total, 'visible_to' => 'rider'];

        return new FareBreakdown($fare, $waiting, $stopsWait, $commission, $taxAmount, $previousBalance, $total, $driverNet, $lines);
    }

    /**
     * The `price.*` keys of a merged policy, without the prefix.
     *
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public static function priceOf(array $policy): array
    {
        $price = [];
        foreach ($policy as $key => $value) {
            if (str_starts_with((string) $key, 'price.')) {
                $price[substr((string) $key, 6)] = $value;
            }
        }

        return $price;
    }
}
