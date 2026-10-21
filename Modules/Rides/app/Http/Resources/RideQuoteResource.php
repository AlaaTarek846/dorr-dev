<?php

namespace Modules\Rides\Http\Resources;

use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rides\Models\RideQuote;
use Modules\Rides\Services\Geo\PickupPoints;
use Modules\Rides\Services\Policy\RidePolicySummary;
use Modules\Rides\Services\Policy\RideTerms;

/**
 * One priced category on the booking sheet: a fixed total (upfront), a range (metered) or the rate
 * card (open destination), the lines the rider may see, and the uuid + fingerprint to confirm with.
 *
 * @mixin RideQuote
 */
class RideQuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $category = $this->category;
        $category?->loadMissing('translations');
        $currency = once(fn () => Currency::query()->find($this->currency_id));

        return [
            'uuid' => $this->uuid,
            'fingerprint' => $this->fingerprint,
            'expires_at' => $this->expires_at->toIso8601String(),
            'category' => [
                'slug' => $category?->slug,
                'name' => $category?->translated('name'),
                'tagline' => $category?->translated('tagline'),
                'color' => $category?->color,
                'capacity' => $category?->capacity_max,
                'icon_url' => $category?->getFirstMediaUrl('icon') ?: null,
                'cash_allowed' => $category?->flag('cash_allowed', true),
            ],
            'currency' => $currency === null ? null
                : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) ($currency->decimal_places ?? 2)],
            'pricing_mode' => $this->pricing_mode->value,
            'open_destination' => $this->destination_lat === null,
            // O-17: this quote is by the hour ({hours, hour_price_minor, overtime_per_min_minor}), or null.
            'hourly' => $this->policy_snapshot['hourly'] ?? null,
            // O-17: the category can also be booked by the hour — the app shows «by distance / by the hour».
            'hourly_offer' => ($p = (array) ($this->policy_snapshot['policy'] ?? [])) !== [] && ! empty($p['hourly.enabled']) && (int) ($p['hourly.hour_price_minor'] ?? 0) > 0 ? [
                'hour_price_minor' => (int) $p['hourly.hour_price_minor'],
                'min_hours' => max(1, (int) ($p['hourly.min_hours'] ?? 1)),
                'max_hours' => max(1, (int) ($p['hourly.max_hours'] ?? 8)),
                'overtime_per_min_minor' => (int) ($p['hourly.overtime_per_min_minor'] ?? 0),
            ] : null,
            // R-233..R-237, R-NEW-06: the add-ons on offer here (with their prices) and the ones priced in.
            'addons_offer' => ($this->policy_snapshot['addons_offer'] ?? null) ?: null,
            'options' => \Modules\Rides\Services\Pricing\TripAddons::present($this->policy_snapshot['options'] ?? null),
            // R-16..R-18: the approved points this quote starts / ends at (the app shows them before confirming).
            'pickup_point' => PickupPoints::present($this->pickup_point),
            'dropoff_point' => PickupPoints::present($this->dropoff_point),
            'total_minor' => $this->total_minor,
            'estimate' => $this->estimate_min_minor === null ? null : ['min_minor' => $this->estimate_min_minor, 'max_minor' => $this->estimate_max_minor],
            'hold_minor' => $this->hold_minor,
            // O-37: what the rider's wallet in this ride's country has free — the booking offers it with cash.
            'wallet_available_minor' => ($w = \Modules\Wallet\Models\Wallet::query()->where('owner_type', 'user')->where('owner_id', $this->user_id)->where('country_id', $this->country_id)->first()) === null
                ? 0 : app(\Modules\Rides\Services\Payment\RidePaymentService::class)->available($w),
            // Bidding: the rider writes their price between the floor and above (the − button stops at the floor).
            'bid' => ($bid = $this->policy_snapshot['bid'] ?? null) === null ? null : [
                'suggested_minor' => (int) $bid['suggested'],
                'floor_minor' => (int) $bid['floor'],
                // − / + move by the policy's first step (5 by default) — the rider can also type the price (O-43).
                'step_minor' => (int) (((array) ($this->policy_snapshot['policy']['bids.counter_steps_minor'] ?? []))[0] ?? 500),
            ],
            // Booking ahead (R-12, R-105): the limits shown under the date picker; not for bidding.
            'scheduling' => ($this->policy_snapshot['bid'] ?? null) !== null ? null : [
                'min_ahead_s' => (int) ($this->policy_snapshot['policy']['scheduled.min_ahead_s'] ?? 3600),
                'max_ahead_s' => (int) ($this->policy_snapshot['policy']['scheduled.max_ahead_s'] ?? 604800),
                'free_cancel_before_s' => (int) ($this->policy_snapshot['policy']['scheduled.free_cancel_before_s'] ?? 3600),
                'cancel_fee_minor' => (int) ($this->policy_snapshot['policy']['price.cancel_fee_minor'] ?? 0),
            ],
            'route' => ['distance_m' => $this->distance_m, 'duration_s' => $this->duration_s, 'polyline' => $this->polyline, 'estimated' => $this->route_estimated],
            'lines' => collect($this->lines)->whereIn('visible_to', ['both', 'rider'])
                ->map(fn (array $l) => ['code' => $l['code'], 'label' => __('rides.lines.'.$l['code']), 'amount_minor' => (int) $l['amount_minor'], 'meta' => $l['meta'] ?? null])
                ->values(),
            // R-45: the coupon typed — what it took off here, or why it didn't apply to this category.
            'coupon' => ($coupon = $this->policy_snapshot['coupon'] ?? null) === null ? null : [
                'code' => $coupon['code'],
                'discount_minor' => (int) $coupon['discount_minor'],
                'applied' => (int) $coupon['discount_minor'] > 0,
                'reason' => $coupon['reason'] === null ? null : __('rides.errors.'.$coupon['reason']),
            ],
            // R-48: what each way of paying adds (the line joins at confirmation, for the method chosen).
            'payment_fees' => $this->policy_snapshot['payment_fees'] ?? null,
            // R-52, R-53: cancellation / no-show / refund, read before confirming.
            'policy_summary' => RidePolicySummary::for((array) ($this->policy_snapshot['policy'] ?? []), $currency),
            // R-179: the terms in force for booking a ride (the rides service's own, else the general ones).
            'terms' => once(fn () => app(RideTerms::class)->current()),
        ];
    }
}
