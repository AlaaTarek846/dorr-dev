<?php

namespace Modules\Moving\Services\Payment;

use Modules\Moving\Models\MovingOffer;
use Modules\Moving\Models\MovingRequest;

/**
 * The money of an accepted offer, from the values kept with the request (REQ-026, REQ-094, REQ-115): the price, the
 * tax shown apart, what the customer pays, Dorr's commission and what the establishment gets. A value the country
 * hasn't set yet counts as zero rather than a made-up number (DEC-020).
 */
class MovingPricing
{
    /** @return array{price_minor: int, tax_minor: int, total_minor: int, commission_minor: int, provider_net_minor: int} */
    public function forOffer(MovingRequest $request, MovingOffer $offer): array
    {
        return $this->forPrice($request, (int) $offer->price_minor);
    }

    /**
     * The same numbers for a price changed by an approved change order (REQ-037).
     *
     * @return array{price_minor: int, tax_minor: int, total_minor: int, commission_minor: int, provider_net_minor: int}
     */
    public function forPrice(MovingRequest $request, int $price): array
    {
        $tax = intdiv($price * (int) ($request->policy('tax.bp') ?? 0), 10000);

        $commission = intdiv($price * (int) ($request->policy('commission.bp') ?? 0), 10000);
        if ($request->policy('commission.min_minor') !== null) {
            $commission = max($commission, (int) $request->policy('commission.min_minor'));
        }
        if ($request->policy('commission.max_minor') !== null) {
            $commission = min($commission, (int) $request->policy('commission.max_minor'));
        }
        $commission = min($commission, $price);

        return [
            'price_minor' => $price,
            'tax_minor' => $tax,
            'total_minor' => $price + $tax,
            'commission_minor' => $commission,
            'provider_net_minor' => $price - $commission,
        ];
    }
}
