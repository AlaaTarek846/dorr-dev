<?php

namespace Modules\Rides\Services\Payment;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Enums\SettlementItemType;
use Modules\Rides\Enums\SettlementStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideSettlementItem;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Stats\DriverStats;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;

/**
 * An optional tip after the ride (rides_tasks R-144, D-182, D-204): one per ride, within
 * `tips.window_h` of the end, up to `tips.max_minor`. It is paid from the rider's wallet — never in
 * cash — and all of it goes to the driver at once, no commission, on its own line apart from the fare.
 *
 * Only the withdrawable balance pays a tip: spend-only credit (prizes, change) can be spent in the
 * app but must never become money a driver withdraws.
 */
class RideTipService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly RidePaymentService $payments,
        private readonly RideFeatures $features,
        private readonly RideStateMachine $machine,
        private readonly RideNotifier $notifier,
        private readonly DriverStats $stats,
    ) {}

    /** What the rider app needs to offer a tip on this ride — null when it can't be tipped (any more). */
    public function offer(Ride $ride): ?array
    {
        if ($ride->tip_minor !== null) {
            return ['allowed' => false, 'amount_minor' => $ride->tip_minor, 'at' => $ride->tipped_at?->toIso8601String()];
        }
        $until = $this->until($ride);
        if ($until === null || $until->isPast() || ! $this->features->allows($ride->country_id, $ride->city_id, 'tips')) {
            return null;
        }

        return [
            'allowed' => true,
            'amount_minor' => null,
            'presets_minor' => array_values(array_map('intval', (array) $ride->policy('tips.presets_minor', config('rides.policy_defaults')['tips.presets_minor'] ?? []))),
            'max_minor' => (int) $ride->policy('tips.max_minor', config('rides.policy_defaults')['tips.max_minor'] ?? 10000),
            'until' => $until->toIso8601String(),
        ];
    }

    public function tip(User $rider, Ride $ride, int $amountMinor, ?string $key = null): Ride
    {
        if ($ride->user_id !== $rider->id) {
            throw new RidesException('ride_not_found', 404);
        }
        // The same tap sent twice gets the same answer.
        if (($done = $this->machine->replayed($ride, $key, 'tipped')) !== null) {
            return $done;
        }

        return DB::transaction(function () use ($ride, $amountMinor, $key) {
            $ride = Ride::query()->whereKey($ride->id)->lockForUpdate()->firstOrFail();
            if ($ride->tip_minor !== null) {
                throw new RidesException('already_tipped', 409);
            }
            if (! $this->features->allows($ride->country_id, $ride->city_id, 'tips')) {
                throw RidesException::featureDisabled('tips');
            }
            $until = $this->until($ride);
            if ($until === null) {
                throw new RidesException('cannot_tip', 409);
            }
            if ($until->isPast()) {
                throw new RidesException('tip_window_closed', 409);
            }
            $max = (int) $ride->policy('tips.max_minor', config('rides.policy_defaults')['tips.max_minor'] ?? 10000);
            if ($amountMinor < 1 || $amountMinor > $max) {
                throw new RidesException('tip_out_of_range', 422, ['max' => $this->notifier->money($max, $ride)], ['max_minor' => $max]);
            }

            $riderWallet = $this->payments->riderWallet($ride)->fresh();
            $available = max(0, $riderWallet->availableMinor(WalletBucket::Withdrawable));
            if ($available < $amountMinor) {
                throw new RidesException('insufficient_balance', 422, [], ['shortfall_minor' => $amountMinor - $available]);
            }

            $driver = $ride->driver()->firstOrFail();
            $driverWallet = $this->wallets->firstOrCreateWallet($driver, $ride->country()->firstOrFail());
            $operation = (string) Str::uuid();
            $meta = fn (string $side, int $counterparty) => [
                'reference_type' => 'ride',
                'reference_id' => $ride->id,
                'notes' => ['key' => 'wallet.notes.ride_tip', 'variables' => ['number' => $ride->number]],
                'idempotency_key' => "ride:{$ride->uuid}:tip:{$side}",
                'operation_id' => $operation,
                'counterparty_wallet_id' => $counterparty,
            ];
            $this->wallets->debit($riderWallet, $amountMinor, WalletBucket::Withdrawable, WalletTransactionType::Tip, $meta('rider', $driverWallet->id));
            $credit = $this->wallets->credit($driverWallet, $amountMinor, WalletBucket::Withdrawable, WalletTransactionType::Tip, $meta('driver', $riderWallet->id));

            RideSettlementItem::query()->create([
                'ride_id' => $ride->id,
                'driver_id' => $driver->id,
                'type' => SettlementItemType::Tip,
                'amount_minor' => $amountMinor,
                'currency_id' => $ride->currency_id,
                'status' => SettlementStatus::Posted,
                'posted_at' => now(),
                'wallet_transaction_id' => $credit->id,
            ]);
            $ride->forceFill(['tip_minor' => $amountMinor, 'tipped_at' => now(), 'version' => $ride->version + 1])->save();
            $this->machine->record($ride, 'tipped', 'user', $ride->user_id, [], ['amount_minor' => $amountMinor], null, null, $key);

            $this->stats->bump($driver->id, ['tips_minor' => $amountMinor]);
            $this->notifier->changed($ride, 'tipped');
            $this->notifier->driver($driver, $ride, 'tip_received', ['amount' => $this->notifier->money($amountMinor, $ride)]);

            return $ride;
        });
    }

    /** Until when this ride can be tipped: a completed ride with a driver, `tips.window_h` after its end. */
    private function until(Ride $ride): ?Carbon
    {
        if ($ride->status !== RideStatus::Completed || $ride->driver_id === null || $ride->completed_at === null) {
            return null;
        }

        return $ride->completed_at->copy()->addHours((int) $ride->policy('tips.window_h', config('rides.policy_defaults')['tips.window_h'] ?? 24));
    }
}
