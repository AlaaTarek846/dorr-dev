<?php

namespace Modules\Rides\Services\Pricing;

use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Services\Features\RideFeatures;

/**
 * What the rider adds to a trip (docs/rides_tasks.md R-233..R-237, R-NEW-06), each switched on per place
 * and priced per category in the rules (`addons.*`):
 *
 *  - Wait & Save: a lower price for a longer wait — the search starts after `addons.wait_save_delay_s`;
 *    the discount (`addons.wait_save_discount_bp` of the fare) is DORR's, like a coupon: the captain's net
 *    is untouched.
 *  - Priority pickup: a fee, and the search goes first and wide at once.
 *  - Child seats (how many), a pet: a fee each, and only captains who said they have / take them are asked.
 *  - A service animal: never a fee and never refused for it (an access need, not a pet) — the captain is told.
 *  - Luggage: small and large bags; a category whose boot can't take them isn't offered
 *    (`addons.luggage_capacity` units: a small bag 1, a large one 2), and large bags may have a fee.
 *
 * The fees are the captain's (they give the extra service): their own lines, added to the total and to the
 * captain's net. They're fixed in the quote (`extras.addons`) and priced again the same way at the end.
 */
final class TripAddons
{
    /** The option → the switch that opens it. */
    public const FEATURES = [
        'wait_save' => 'wait_save',
        'priority' => 'priority_pickup',
        'child_seats' => 'child_seat',
        'pet' => 'pet',
        'service_animal' => 'service_animal',
        'luggage' => 'luggage',
    ];

    /** The fee lines, in the order they show. */
    public const LINES = ['addon_priority', 'addon_child_seat', 'addon_pet', 'addon_luggage'];

    /**
     * What the rider asked for, cleaned: only what's asked, with sane bounds.
     *
     * @param  array<string, mixed>|null  $input
     * @return array{wait_save?: true, priority?: true, child_seats?: int, pet?: true, service_animal?: true, bags_small?: int, bags_large?: int}
     */
    public static function normalize(?array $input): array
    {
        $in = (array) $input;
        $out = array_filter([
            'wait_save' => ! empty($in['wait_save']) ? true : null,
            'priority' => ! empty($in['priority']) ? true : null,
            'child_seats' => min(3, max(0, (int) ($in['child_seats'] ?? 0))) ?: null,
            'pet' => ! empty($in['pet']) ? true : null,
            'service_animal' => ! empty($in['service_animal']) ? true : null,
            'bags_small' => min(10, max(0, (int) ($in['bags_small'] ?? 0))) ?: null,
            'bags_large' => min(10, max(0, (int) ($in['bags_large'] ?? 0))) ?: null,
        ], fn ($v) => $v !== null);
        if (isset($out['wait_save'], $out['priority'])) {
            throw new RidesException('addons_wait_or_priority', 422);
        }

        return $out;
    }

    /** Each asked option must be switched on where the trip starts. */
    public static function assertAllowed(RideFeatures $features, int $countryId, ?int $cityId, array $options): void
    {
        foreach (self::switchesFor($options) as $feature) {
            if (! $features->allows($countryId, $cityId, $feature)) {
                throw RidesException::featureDisabled($feature);
            }
        }
    }

    /** @return list<string> */
    public static function switchesFor(array $options): array
    {
        $out = [];
        foreach ($options as $key => $_) {
            $out[] = self::FEATURES[$key === 'bags_small' || $key === 'bags_large' ? 'luggage' : $key];
        }

        return array_values(array_unique($out));
    }

    /**
     * For one category: its fee lines and the wait & save discount — or why it can't take these options.
     *
     * @param  array<string, mixed>  $policy
     * @return array{fees: list<array{code: string, minor: int, meta: array<string, int>}>, wait_save: ?array{bp: int, delay_s: int}}|string
     */
    public static function forCategory(array $options, array $policy): array|string
    {
        $int = fn (string $k, int $d = 0) => (int) ($policy[$k] ?? $d);
        $fees = [];
        if (isset($options['child_seats'])) {
            if ($options['child_seats'] > $int('addons.child_seat_max', 2)) {
                return 'addons_child_seats_max';
            }
            $fees[] = ['code' => 'addon_child_seat', 'minor' => $options['child_seats'] * $int('addons.child_seat_fee_minor'), 'meta' => ['count' => $options['child_seats']]];
        }
        if (isset($options['pet'])) {
            if (! ($policy['addons.pet_allowed'] ?? true)) {
                return 'addons_pet_not_here';
            }
            $fees[] = ['code' => 'addon_pet', 'minor' => $int('addons.pet_fee_minor'), 'meta' => []];
        }
        $small = (int) ($options['bags_small'] ?? 0);
        $large = (int) ($options['bags_large'] ?? 0);
        if ($small + $large > 0) {
            if ($small + 2 * $large > $int('addons.luggage_capacity', 4)) {
                return 'addons_luggage_too_much';
            }
            $fees[] = ['code' => 'addon_luggage', 'minor' => $large * $int('addons.large_bag_fee_minor'), 'meta' => ['small' => $small, 'large' => $large]];
        }
        if (isset($options['priority'])) {
            $fees[] = ['code' => 'addon_priority', 'minor' => $int('addons.priority_fee_minor'), 'meta' => []];
        }
        // Only the lines that cost something; a free add-on still counts for matching.
        $fees = array_values(array_filter($fees, fn (array $f) => $f['minor'] > 0));
        usort($fees, fn ($a, $b) => array_search($a['code'], self::LINES, true) <=> array_search($b['code'], self::LINES, true));

        return [
            'fees' => $fees,
            'wait_save' => isset($options['wait_save']) ? ['bp' => min(9000, $int('addons.wait_save_discount_bp', 1000)), 'delay_s' => $int('addons.wait_save_delay_s', 300)] : null,
        ];
    }

    /**
     * What the booking sheet offers for this category here: each add-on switched on, with its price.
     *
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public static function offer(RideFeatures $features, int $countryId, ?int $cityId, array $policy): array
    {
        $on = fn (string $key) => $features->allows($countryId, $cityId, self::FEATURES[$key]);
        $int = fn (string $k, int $d = 0) => (int) ($policy[$k] ?? $d);

        return array_filter([
            'wait_save' => $on('wait_save') ? ['discount_bp' => $int('addons.wait_save_discount_bp', 1000), 'extra_wait_s' => $int('addons.wait_save_delay_s', 300)] : null,
            'priority' => $on('priority') ? ['fee_minor' => $int('addons.priority_fee_minor')] : null,
            'child_seats' => $on('child_seats') ? ['fee_minor' => $int('addons.child_seat_fee_minor'), 'max' => $int('addons.child_seat_max', 2)] : null,
            'pet' => $on('pet') && ($policy['addons.pet_allowed'] ?? true) ? ['fee_minor' => $int('addons.pet_fee_minor')] : null,
            'service_animal' => $on('service_animal') ? ['fee_minor' => 0] : null,
            'luggage' => $on('luggage') ? ['capacity' => $int('addons.luggage_capacity', 4), 'large_fee_minor' => $int('addons.large_bag_fee_minor')] : null,
        ]);
    }

    /**
     * The options as both apps show them (the captain's request card, the rider's ride).
     *
     * @return list<array{key: string, label: string, count?: int}>
     */
    public static function present(?array $options): array
    {
        $out = [];
        foreach ((array) $options as $key => $value) {
            if ($key === 'bags_small' || $key === 'bags_large') {
                continue;
            }
            $out[] = ['key' => $key, 'label' => __('rides.addons.'.$key)] + (is_int($value) ? ['count' => $value] : []);
        }
        $small = (int) ($options['bags_small'] ?? 0);
        $large = (int) ($options['bags_large'] ?? 0);
        if ($small + $large > 0) {
            $out[] = ['key' => 'luggage', 'label' => __('rides.addons.luggage_n', ['small' => $small, 'large' => $large]), 'small' => $small, 'large' => $large];
        }

        return $out;
    }
}
