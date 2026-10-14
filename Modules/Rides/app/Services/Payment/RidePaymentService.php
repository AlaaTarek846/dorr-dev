<?php

namespace Modules\Rides\Services\Payment;

use Illuminate\Support\Facades\DB;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideHold;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;

/**
 * The rider's money for a ride (docs/rides-plan.md §7.6). A wallet ride holds the amount at
 * confirmation — spend-only first, then withdrawable — and nothing moves until the end, when the
 * final price is captured and the rest released (no refund, no double charge: R-63, R-64).
 */
class RidePaymentService
{
    public function __construct(private readonly WalletService $wallets) {}

    public function riderWallet(Ride $ride): Wallet
    {
        return $this->wallets->firstOrCreateWallet($ride->rider()->firstOrFail(), $ride->country()->firstOrFail());
    }

    /** Spend-only first, then withdrawable — like the checkout. */
    public function available(Wallet $wallet): int
    {
        return max(0, $wallet->availableMinor(WalletBucket::SpendOnly)) + max(0, $wallet->availableMinor(WalletBucket::Withdrawable));
    }

    /**
     * @throws RidesException insufficient_balance (with the shortfall) when the wallet can't cover it
     */
    public function hold(Ride $ride, int $amountMinor, string $reason = 'initial'): void
    {
        if ($amountMinor <= 0) {
            return;
        }

        DB::transaction(function () use ($ride, $amountMinor, $reason) {
            $wallet = $this->riderWallet($ride)->fresh();
            $available = $this->available($wallet);
            if ($available < $amountMinor) {
                throw new RidesException('insufficient_balance', 422, [], ['shortfall_minor' => $amountMinor - $available]);
            }

            $fromSpendOnly = min(max(0, $wallet->availableMinor(WalletBucket::SpendOnly)), $amountMinor);
            $parts = [[WalletBucket::SpendOnly, $fromSpendOnly], [WalletBucket::Withdrawable, $amountMinor - $fromSpendOnly]];

            foreach ($parts as [$bucket, $part]) {
                if ($part <= 0) {
                    continue;
                }
                $hold = $this->wallets->hold($wallet, $part, $bucket, [
                    'reference_type' => 'ride',
                    'reference_id' => $ride->id,
                    'reason_code' => 'ride_'.$reason,
                    // A safety valve: a hold nobody closes frees itself a day later — a day after the
                    // booked time for a ride booked ahead, which may be a week away.
                    'expires_at' => ($ride->scheduled_at?->isFuture() ? $ride->scheduled_at->copy() : now())->addDay(),
                    'idempotency_key' => "ride:{$ride->uuid}:{$reason}:{$bucket->value}",
                ]);
                RideHold::query()->create(['ride_id' => $ride->id, 'wallet_hold_id' => $hold->id, 'reason' => $reason, 'amount_minor' => $part, 'created_at' => now()]);
                $wallet->refresh();
            }
        });
    }

    /**
     * Charges the final amount from the holds (spend-only first); anything beyond them (waiting
     * fees) is debited from the withdrawable balance, which may go below zero as a debt.
     */
    public function capture(Ride $ride, int $amountMinor, string $noteKey = 'ride_payment'): ?WalletTransaction
    {
        return DB::transaction(function () use ($ride, $amountMinor, $noteKey) {
            $remaining = $amountMinor;
            $last = null;
            $meta = fn (string $suffix) => [
                'reference_type' => 'ride',
                'reference_id' => $ride->id,
                'notes' => ['key' => 'wallet.notes.'.$noteKey, 'variables' => ['number' => $ride->number]],
                'idempotency_key' => "ride:{$ride->uuid}:{$noteKey}:{$suffix}",
            ];

            foreach ($this->activeHolds($ride) as $rideHold) {
                $walletHold = $rideHold->walletHold;
                $part = min($remaining, $walletHold->amount_minor);
                if ($part > 0) {
                    $last = $this->wallets->capture($walletHold, $part, WalletTransactionType::ServicePayment, $meta('hold:'.$walletHold->id));
                    $remaining -= $part;
                } else {
                    $this->wallets->release($walletHold, 'ride_not_needed');
                }
            }

            if ($remaining > 0) {
                $last = $this->wallets->debit($this->riderWallet($ride), $remaining, WalletBucket::Withdrawable, WalletTransactionType::ServicePayment, $meta('extra'));
            }

            return $last;
        });
    }

    public function releaseAll(Ride $ride, string $reason): void
    {
        foreach ($this->activeHolds($ride) as $rideHold) {
            $this->wallets->release($rideHold->walletHold, $reason);
        }
    }

    /** A cash ride's fee (cancellation, no-show): the rider's wallet owes it, as a debt if need be. */
    public function chargeDebt(Ride $ride, int $amountMinor, string $noteKey): ?WalletTransaction
    {
        if ($amountMinor <= 0) {
            return null;
        }

        return $this->wallets->debit($this->riderWallet($ride), $amountMinor, WalletBucket::Withdrawable, WalletTransactionType::Penalty, [
            'reference_type' => 'ride',
            'reference_id' => $ride->id,
            'notes' => ['key' => 'wallet.notes.'.$noteKey, 'variables' => ['number' => $ride->number]],
            'idempotency_key' => "ride:{$ride->uuid}:{$noteKey}",
        ]);
    }

    /** What the rider owes from earlier rides (a negative withdrawable balance), collected with a cash ride. */
    public function debtOf(Ride $ride): int
    {
        $wallet = Wallet::query()
            ->where('owner_type', 'user')->where('owner_id', $ride->user_id)->where('country_id', $ride->country_id)
            ->first();

        return $wallet === null ? 0 : max(0, -$wallet->withdrawable_minor);
    }

    /** @return iterable<RideHold> */
    private function activeHolds(Ride $ride): iterable
    {
        return RideHold::query()->where('ride_id', $ride->id)->with('walletHold')->orderBy('id')->get()
            ->filter(fn (RideHold $h) => $h->walletHold?->status === WalletHoldStatus::Active)
            // Spend-only first, like the checkout.
            ->sortBy(fn (RideHold $h) => $h->walletHold->bucket === WalletBucket::SpendOnly ? 0 : 1);
    }
}
