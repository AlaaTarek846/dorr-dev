<?php

namespace Modules\Rides\Services\Pricing;

/**
 * What the rider pays besides the fare (rides_tasks R-45, R-47, R-48), each on its own line:
 *
 *   customer fee = fixed + fare part × rate             — DORR's (R-47)
 *   discount     = the coupon on (fare part + customer fee) — DORR pays it, never the driver (R-45)
 *   payment fee  = the chosen method's fixed + rate × what is charged — DORR's (R-48)
 *
 * The driver's net is untouched: it is worked out on the fare before any of these. The same
 * `extras` (fixed in the quote, then in the ride's snapshot) give the same lines at the quote, after
 * a change during the ride, and at the end.
 */
final class PriceExtras
{
    /**
     * DORR's lines (its fees, and the discounts it pays for), in the order they show — before the old
     * debt / commission / totals. The add-on fees (TripAddons::LINES) are the captain's, not here.
     */
    public const CODES = ['customer_fee', 'wait_save', 'discount', 'payment_method_fee'];

    private const TAIL = ['previous_balance', 'commission', 'driver_net', 'total'];

    /**
     * @param  array{customer_fee?: array{minor: int, bp: int}|null, coupon?: array{id: int, code: string, kind: string, value: int, max_discount_minor: ?int, min_amount_minor?: ?int}|null, payment_fee?: array{method: string, minor: int, bp: int}|null}  $extras
     */
    public static function apply(FareBreakdown $b, array $extras): FareBreakdown
    {
        $earned = $b->fare + $b->waiting + $b->stopsWait;
        $lines = $b->lines;

        // R-234..R-237, R-NEW-06: the add-ons the rider chose — the captain's, on their net too.
        $addons = 0;
        foreach ((array) ($extras['addons'] ?? []) as $fee) {
            $minor = max(0, (int) ($fee['minor'] ?? 0));
            if ($minor > 0) {
                $lines = self::insert($lines, ['code' => (string) $fee['code'], 'amount_minor' => $minor, 'visible_to' => 'both', 'meta' => (array) ($fee['meta'] ?? [])]);
                $addons += $minor;
            }
        }
        // R-233: Wait & Save — a share of the fare off, DORR's like a coupon (the captain's net stays).
        $waitSave = 0;
        if (($ws = $extras['wait_save'] ?? null) !== null) {
            $waitSave = intdiv($b->fare * (int) ($ws['bp'] ?? 0) + 5000, 10000);
            if ($waitSave > 0) {
                $lines = self::insert($lines, ['code' => 'wait_save', 'amount_minor' => -$waitSave, 'visible_to' => 'both', 'meta' => ['bp' => (int) $ws['bp']]]);
            }
        }

        $service = 0;
        if (($fee = $extras['customer_fee'] ?? null) !== null) {
            $service = max(0, (int) ($fee['minor'] ?? 0) + intdiv($earned * (int) ($fee['bp'] ?? 0) + 5000, 10000));
            if ($service > 0) {
                $lines = self::insert($lines, ['code' => 'customer_fee', 'amount_minor' => $service, 'visible_to' => 'both', 'meta' => ['minor' => (int) ($fee['minor'] ?? 0), 'bp' => (int) ($fee['bp'] ?? 0)]]);
            }
        }

        // The rider-borne tax is part of what the coupon is taken from; the old debt never is.
        $base = $b->total - $b->previousBalance + $service + $addons - $waitSave;
        $discount = 0;
        if (($coupon = $extras['coupon'] ?? null) !== null && $base >= (int) ($coupon['min_amount_minor'] ?? 0)) {
            $discount = self::discountOn($coupon, $base);
            if ($discount > 0) {
                $lines = self::insert($lines, ['code' => 'discount', 'amount_minor' => -$discount, 'visible_to' => 'both', 'meta' => ['code' => $coupon['code'], 'kind' => $coupon['kind'], 'value' => (int) $coupon['value']]]);
            }
        }

        $payment = 0;
        if (($fee = $extras['payment_method_fee'] ?? null) !== null) {
            $payment = self::paymentFee((int) ($fee['minor'] ?? 0), (int) ($fee['bp'] ?? 0), $base - $discount);
            if ($payment > 0) {
                $lines = self::insert($lines, ['code' => 'payment_method_fee', 'amount_minor' => $payment, 'visible_to' => 'both', 'meta' => ['method' => $fee['method'], 'minor' => (int) ($fee['minor'] ?? 0), 'bp' => (int) ($fee['bp'] ?? 0)]]);
            }
        }

        $total = $b->total + $service + $addons - $waitSave - $discount + $payment;
        $driverNet = $b->driverNet + $addons;
        $lines = array_map(fn (array $l) => match ($l['code']) {
            'total' => ['amount_minor' => $total] + $l,
            'driver_net' => ['amount_minor' => $driverNet] + $l,
            default => $l,
        }, $lines);

        return new FareBreakdown($b->fare, $b->waiting, $b->stopsWait, $b->commission, $b->tax, $b->previousBalance, $total, $driverNet, $lines, $service, $discount + $waitSave, $payment);
    }

    /** A method's fee on an amount: fixed + rate (basis points), rounded half up. */
    public static function paymentFee(int $minor, int $bp, int $amount): int
    {
        return max(0, $minor + intdiv(max(0, $amount) * $bp + 5000, 10000));
    }

    /**
     * Same rule as the wallet's coupons (WalletCoupon::discountOn), from the values fixed in the quote.
     *
     * @param  array{kind: string, value: int, max_discount_minor?: ?int}  $coupon
     */
    public static function discountOn(array $coupon, int $amount): int
    {
        $d = $coupon['kind'] === 'percent' ? intdiv($amount * min(100, (int) $coupon['value']), 100) : (int) $coupon['value'];
        if (($coupon['max_discount_minor'] ?? null) !== null) {
            $d = min($d, (int) $coupon['max_discount_minor']);
        }

        return max(0, min($amount, $d));
    }

    /**
     * Puts a line before the old debt / commission / totals, or replaces the one with its code.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $line
     * @return list<array<string, mixed>>
     */
    public static function insert(array $lines, array $line): array
    {
        $lines = array_values(array_filter($lines, fn (array $l) => $l['code'] !== $line['code']));
        $at = count($lines);
        foreach ($lines as $i => $l) {
            if (in_array($l['code'], self::TAIL, true)) {
                $at = $i;

                break;
            }
        }
        array_splice($lines, $at, 0, [$line]);

        return $lines;
    }
}
