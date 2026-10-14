<?php

namespace Modules\Rides\Services\Policy;

use App\Models\Currency;

/**
 * What the rider reads before confirming (rides_tasks R-52, R-53): the cancellation and no-show rules
 * and whether the money comes back — written from the very policy the quote fixed, so what is shown
 * is what will apply. Texts come in the rider's language; the numbers come along for the apps.
 */
final class RidePolicySummary
{
    /**
     * @param  array<string, mixed>  $policy  a merged policy (RidePolicyData::all() or a snapshot)
     * @return array<string, mixed>
     */
    public static function for(array $policy, ?Currency $currency): array
    {
        $fee = (int) ($policy['price.cancel_fee_minor'] ?? 0);
        $freeAfterAccept = (int) ($policy['cancel.free_after_accept_s'] ?? 120);
        $dailyFree = (int) ($policy['cancel.rider_daily_free'] ?? 3);
        $noShowWait = (int) ($policy['no_show.wait_s'] ?? 300);
        $money = self::money($fee, $currency);
        $minutes = fn (int $s) => max(1, intdiv($s + 59, 60));

        $items = [['code' => 'cancel_before_accept', 'text' => __('rides.policy.cancel_before_accept')]];
        $items[] = $fee > 0
            ? ['code' => 'cancel_after_accept', 'text' => __('rides.policy.cancel_after_accept_fee', ['minutes' => $minutes($freeAfterAccept), 'fee' => $money, 'count' => $dailyFree])]
            : ['code' => 'cancel_after_accept', 'text' => __('rides.policy.cancel_after_accept_free')];
        $items[] = $fee > 0
            ? ['code' => 'no_show', 'text' => __('rides.policy.no_show_fee', ['minutes' => $minutes($noShowWait), 'fee' => $money])]
            : ['code' => 'no_show', 'text' => __('rides.policy.no_show_free', ['minutes' => $minutes($noShowWait)])];

        return [
            'cancel' => ['fee_minor' => $fee, 'free_after_accept_s' => $freeAfterAccept, 'daily_free' => $dailyFree],
            'no_show' => ['wait_s' => $noShowWait, 'fee_minor' => $fee],
            'items' => $items,
            // R-52: by the way of paying — a held amount comes back, cash has nothing to come back.
            'refund' => [
                'wallet' => __('rides.policy.refund_held'),
                'card' => __('rides.policy.refund_held'),
                'cash' => __('rides.policy.refund_cash'),
            ],
        ];
    }

    public static function money(int $minor, ?Currency $currency): string
    {
        $places = (int) ($currency?->decimal_places ?? 2);

        return trim(number_format($minor / (10 ** $places), $places).' '.($currency?->symbol ?? ''));
    }
}
