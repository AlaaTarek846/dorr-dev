<?php

namespace Modules\Rides\Services\Payment;

use Illuminate\Support\Facades\DB;
use Modules\Rides\Enums\BookingMode;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Pricing\PriceExtras;
use Modules\User\Models\User;

/**
 * The rider changes how they pay before the trip starts (rides_tasks R-61): wallet ↔ cash — a card
 * ride can move to either (its money already sits held in the wallet). Moving to a card needs the
 * payment screen, so it is not offered here.
 *
 * Everything moves together, or nothing does: the old method's fee and (for cash) the old debt come
 * off the confirmed lines; the new method's fee (R-48) and, for cash, a debt still owed join them;
 * the agreed price and the hold follow; a wallet ride holds the new amount — not enough balance
 * undoes it all. The driver is told.
 */
class RidePaymentChangeService
{
    /** Before the trip starts — a ride on the road keeps the way it is paid. */
    private const OPEN = [RideStatus::Searching, RideStatus::Bidding, RideStatus::Scheduled, RideStatus::Accepted, RideStatus::Arrived];

    private const TARGETS = [RidePaymentMethod::Wallet, RidePaymentMethod::Cash];

    public function __construct(
        private readonly RidePaymentService $payments,
        private readonly RideFeatures $features,
        private readonly RideStateMachine $machine,
        private readonly RideNotifier $notifier,
    ) {}

    /**
     * The methods the rider may switch to now (empty when none).
     *
     * @return list<string>
     */
    public static function options(Ride $ride): array
    {
        if (! in_array($ride->status, self::OPEN, true)) {
            return [];
        }
        $service = app(self::class);

        return array_values(array_map(
            fn (RidePaymentMethod $m) => $m->value,
            array_filter(self::TARGETS, fn (RidePaymentMethod $m) => $m !== $ride->payment_method && $service->allowed($ride, $m)),
        ));
    }

    public function change(User $rider, Ride $ride, RidePaymentMethod $to, ?string $key = null): Ride
    {
        if ($ride->user_id !== $rider->id) {
            throw new RidesException('ride_not_found', 404);
        }
        if (($done = $this->machine->replayed($ride, $key, 'payment_changed')) !== null) {
            return $done;
        }
        if ($ride->payment_method === $to) {
            return $ride;
        }
        if (! in_array($to, self::TARGETS, true) || ! in_array($ride->status, self::OPEN, true)) {
            throw new RidesException('payment_change_unavailable', 409);
        }
        if (! $this->allowed($ride, $to)) {
            throw RidesException::featureDisabled($to->value);
        }

        $ride = DB::transaction(function () use ($ride, $to, $key) {
            $ride = Ride::query()->whereKey($ride->id)->lockForUpdate()->firstOrFail();
            if (! in_array($ride->status, self::OPEN, true)) {
                throw new RidesException('payment_change_unavailable', 409);
            }
            $from = $ride->payment_method;
            $policy = (array) $ride->snapshot('policy', []);
            $extras = (array) $ride->snapshot('extras', []);
            $lines = RidePriceLine::query()->where('ride_id', $ride->id)->where('phase', 'confirmed')->orderBy('sort_order')->get()
                ->map(fn (RidePriceLine $l) => ['code' => $l->code, 'amount_minor' => (int) $l->amount_minor, 'visible_to' => $l->visible_to, 'meta' => $l->meta])->all();
            $sum = fn (array $ls, string $code) => (int) collect($ls)->where('code', $code)->sum('amount_minor');

            // Off: the old method's fee, and the debt only a cash ride collects.
            $oldFee = $sum($lines, 'payment_method_fee');
            $oldDebt = (int) $ride->previous_balance_minor;
            $lines = array_values(array_filter($lines, fn (array $l) => ! in_array($l['code'], ['payment_method_fee', 'previous_balance'], true)));
            unset($extras['payment_method_fee']);

            // On: the new method's fee (R-48) on what is charged, and for cash a debt still owed.
            $bidding = $ride->booking_mode === BookingMode::Bid;
            $total = $sum($lines, 'total') - $oldFee - $oldDebt;
            $newFee = 0;
            $debt = 0;
            if ($to === RidePaymentMethod::Cash) {
                $minor = (int) ($policy['payment.cash_fee_minor'] ?? 0);
                if (! $bidding && $minor > 0) {
                    $extras['payment_method_fee'] = ['method' => $to->value, 'minor' => $minor, 'bp' => 0];
                    $newFee = PriceExtras::paymentFee($minor, 0, $total);
                    if ($lines !== []) {
                        $lines = PriceExtras::insert($lines, ['code' => 'payment_method_fee', 'amount_minor' => $newFee, 'visible_to' => 'both', 'meta' => $extras['payment_method_fee']]);
                    }
                }
                $debt = $this->payments->debtOf($ride);
                if ($debt > 0 && $lines !== []) {
                    $lines = PriceExtras::insert($lines, ['code' => 'previous_balance', 'amount_minor' => $debt, 'visible_to' => 'both']);
                }
            }
            $lines = array_map(fn (array $l) => $l['code'] === 'total' ? ['amount_minor' => $total + $newFee + $debt] + $l : $l, $lines);

            RidePriceLine::query()->where('ride_id', $ride->id)->where('phase', 'confirmed')->delete();
            foreach ($lines as $i => $line) {
                RidePriceLine::query()->create([
                    'ride_id' => $ride->id, 'phase' => 'confirmed', 'code' => $line['code'], 'amount_minor' => $line['amount_minor'],
                    'visible_to' => $line['visible_to'], 'meta' => $line['meta'] ?? null, 'sort_order' => $i, 'created_at' => now(),
                ]);
            }

            $hold = $bidding ? (int) ($ride->agreed_price_minor ?? $ride->rider_offer_minor) : (int) $ride->snapshot('hold_minor', 0) - $oldFee + $newFee;
            $snapshot = $ride->confirmed_snapshot;
            $snapshot['extras'] = $extras === [] ? null : $extras;
            $snapshot['hold_minor'] = $bidding ? ($snapshot['hold_minor'] ?? $hold) : $hold;
            if (isset($snapshot['breakdown']) && is_array($snapshot['breakdown'])) {
                $snapshot['breakdown']['payment_method_fee'] = $newFee;
                $snapshot['breakdown']['total'] = $total + $newFee + $debt;
            }

            // The money: a held ride lets go of its hold; a wallet ride holds the new amount (all or nothing).
            if ($from->holdsInWallet()) {
                $this->payments->releaseAll($ride, 'ride_payment_changed');
            }
            $ride->forceFill([
                'payment_method' => $to,
                'payment_status' => $to === RidePaymentMethod::Wallet ? RidePaymentStatus::Held : RidePaymentStatus::Pending,
                'previous_balance_minor' => $debt,
                'agreed_price_minor' => $ride->agreed_price_minor === null || $bidding ? $ride->agreed_price_minor : (int) $ride->agreed_price_minor - $oldFee - $oldDebt + $newFee + $debt,
                'confirmed_snapshot' => $snapshot,
                'version' => $ride->version + 1,
            ])->save();
            if ($to === RidePaymentMethod::Wallet) {
                $this->payments->hold($ride, $hold, 'switch_'.$ride->version);
            }

            $this->machine->record($ride, 'payment_changed', 'user', $ride->user_id, [], ['from' => $from->value, 'to' => $to->value, 'fee_minor' => $newFee, 'debt_minor' => $debt], null, null, $key);

            return $ride;
        });

        $this->notifier->changed($ride, 'payment_changed');
        if ($ride->driver_id !== null && $ride->driver !== null) {
            $this->notifier->driver($ride->driver, $ride, 'payment_changed', ['method' => __('rides.payment_methods.'.$to->value)]);
        }

        return $ride->refresh();
    }

    /** Switched on where the ride is; cash also needs the category and the policy to allow it. */
    public function allowed(Ride $ride, RidePaymentMethod $to): bool
    {
        if (! $this->features->allows($ride->country_id, $ride->city_id, $to->value)) {
            return false;
        }
        if ($to === RidePaymentMethod::Cash) {
            $ride->loadMissing('category');

            return (bool) $ride->policy('cash.allowed', true) && (bool) ($ride->category?->flag('cash_allowed', true) ?? true);
        }

        return true;
    }
}
