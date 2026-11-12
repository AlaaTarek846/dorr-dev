<?php

namespace Modules\Moving\Services\Payment;

use App\Models\Country;
use Illuminate\Support\Collection;
use Modules\Moving\Models\MovingRequest;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Services\WalletService;

/**
 * The customer's money for a move (owner's call: the whole amount is held when an offer is accepted, and moves to
 * the establishment only after delivery). Held like a freelance milestone: no end date, so the wallet's clock never
 * frees it; one hold per bucket, each with its own key so a retry holds nothing twice.
 */
class MovingPayments
{
    public const REFERENCE = 'moving_request';

    public function __construct(private readonly WalletService $wallets) {}

    /** `$step` tells holds of the same request apart (the first payment, an approved change order…). */
    public function hold(MovingRequest $request, User $customer, int $amountMinor, string $step = ''): void
    {
        $wallet = $this->wallets->firstOrCreateWallet($customer, Country::query()->findOrFail($request->country_id));
        foreach ($wallet->spendParts($amountMinor) as [$bucket, $part]) {
            if ($part <= 0) {
                continue;
            }
            $this->wallets->hold($wallet, $part, $bucket, [
                'reference_type' => self::REFERENCE,
                'reference_id' => $request->id,
                'reason_code' => 'moving_request',
                'expires_at' => null,
                'idempotency_key' => "moving:{$request->uuid}".($step !== '' ? ":{$step}" : '').":{$bucket->value}",
            ]);
            $wallet->refresh();
        }
    }

    public function held(MovingRequest $request): int
    {
        return (int) $this->activeHolds($request)->sum('amount_minor');
    }

    /** @return Collection<int, WalletHold> spend-only first */
    public function activeHolds(MovingRequest $request): Collection
    {
        return WalletHold::query()->where('reference_type', self::REFERENCE)->where('reference_id', $request->id)
            ->where('status', WalletHoldStatus::Active)->get()
            ->sortBy(fn (WalletHold $h) => $h->bucket === WalletBucket::SpendOnly ? 0 : 1)->values();
    }
}
