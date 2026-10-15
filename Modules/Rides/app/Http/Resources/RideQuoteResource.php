<?php

namespace Modules\Rides\Http\Resources;

use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rides\Models\RideQuote;
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
            'total_minor' => $this->total_minor,
            'estimate' => $this->estimate_min_minor === null ? null : ['min_minor' => $this->estimate_min_minor, 'max_minor' => $this->estimate_max_minor],
            'hold_minor' => $this->hold_minor,
            // Bidding: the rider writes their price between the floor and above (the − button stops at the floor).
            'bid' => ($bid = $this->policy_snapshot['bid'] ?? null) === null ? null : [
                'suggested_minor' => (int) $bid['suggested'],
                'floor_minor' => (int) $bid['floor'],
                'step_minor' => 100,
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
