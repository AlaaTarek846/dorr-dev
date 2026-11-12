<?php

namespace Modules\WatchEarn\Services;

use App\Models\Country;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Modules\WatchEarn\Enums\AdStatus;
use Modules\WatchEarn\Exceptions\WatchEarnException;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdWallet;
use Modules\WatchEarn\Models\AdWalletEntry;

/**
 * The ad wallet's only door (WE-011..WE-014, D12–D14). Every amount remembers the wallet bucket it came from:
 * - funding from the wallet and every spend take the money that can't be withdrawn first (SPEND_ORDER);
 * - a return gives the withdrawable money back first (RETURN_ORDER), each part into the bucket it came from — a
 *   reward stays a reward;
 * - an ad's reserved budget is locked until it closes; the free balance goes back at any time.
 */
class AdWalletService
{
    /** NEEDS-DECISION D20: rewards before spend_only is the working order until the owner confirms it. */
    public const SPEND_ORDER = ['reward', 'spend_only', 'withdrawable'];

    public const RETURN_ORDER = ['withdrawable', 'spend_only', 'reward'];

    public function __construct(private readonly WalletService $wallets) {}

    public function for(User $user, Country $country): AdWallet
    {
        return AdWallet::query()->firstOrCreate(
            ['user_id' => $user->id, 'country_id' => $country->id],
            ['currency_id' => $country->currency_id],
        );
    }

    /**
     * What a move from the wallet would take from each bucket — the preview the app shows before the PIN.
     *
     * @return array{reward: int, spend_only: int, withdrawable: int}
     */
    public function fundingSplit(?Wallet $wallet, int $amountMinor): array
    {
        $available = [
            'reward' => max(0, $wallet?->availableMinor(WalletBucket::Reward) ?? 0),
            'spend_only' => max(0, $wallet?->availableMinor(WalletBucket::SpendOnly) ?? 0),
            'withdrawable' => max(0, $wallet?->availableMinor(WalletBucket::Withdrawable) ?? 0),
        ];

        $split = $this->take($available, $amountMinor, self::SPEND_ORDER);
        if ($split === null) {
            throw new WatchEarnException('insufficient_wallet', 422, [], ['available_minor' => array_sum($available)]);
        }

        return $split;
    }

    /** WE-012 — from the user's wallet into their ad wallet. */
    public function fund(User $user, Country $country, int $amountMinor, string $idempotencyKey): AdWalletEntry
    {
        $this->assertPositive($amountMinor);

        return DB::transaction(function () use ($user, $country, $amountMinor, $idempotencyKey) {
            $key = "we:fund:{$user->id}:{$idempotencyKey}";
            if ($done = AdWalletEntry::query()->where('idempotency_key', $key)->first()) {
                return $done;
            }

            $wallet = Wallet::query()->lockForUpdate()->find($this->wallets->firstOrCreateWallet($user, $country)->id);
            $adWallet = $this->lock($this->for($user, $country));
            $split = $this->fundingSplit($wallet, $amountMinor);
            $operationId = (string) Str::uuid();

            foreach ($split as $bucket => $part) {
                if ($part > 0) {
                    $this->wallets->debit($wallet, $part, WalletBucket::from($bucket), WalletTransactionType::AdFunding, [
                        'idempotency_key' => "{$key}:{$bucket}",
                        'operation_id' => $operationId,
                        'reference_type' => 'we_ad_wallet',
                        'reference_id' => $adWallet->id,
                        'notes' => ['key' => 'wallet.notes.ad_funding'],
                    ]);
                    $wallet->refresh();
                }
            }

            return $this->book($adWallet, AdWalletEntry::FUND, $split, +1, null, $key, $operationId);
        });
    }

    /**
     * WE-013 — the payment screen paid for an ad wallet top-up (AdWalletTopupPurpose). Through a gateway the money
     * just landed in the wallet as withdrawable and moves on as withdrawable (D12); paid from the wallet's balance it
     * moves like a fund (non-withdrawable first). Inside the payment's transaction, once per checkout.
     */
    public function fromCheckout(User $user, Country $country, int $amountMinor, bool $viaGateway, string $checkoutUuid): AdWalletEntry
    {
        $this->assertPositive($amountMinor);
        $key = "we:checkout:{$checkoutUuid}";
        if ($done = AdWalletEntry::query()->where('idempotency_key', $key)->first()) {
            return $done;
        }

        $wallet = Wallet::query()->lockForUpdate()->find($this->wallets->firstOrCreateWallet($user, $country)->id);
        $adWallet = $this->lock($this->for($user, $country));
        $split = $viaGateway
            ? ['reward' => 0, 'spend_only' => 0, 'withdrawable' => $amountMinor]
            : $this->fundingSplit($wallet, $amountMinor);
        $operationId = (string) Str::uuid();

        foreach ($split as $bucket => $part) {
            if ($part > 0) {
                $this->wallets->debit($wallet, $part, WalletBucket::from($bucket), WalletTransactionType::AdFunding, [
                    'idempotency_key' => "{$key}:{$bucket}",
                    'operation_id' => $operationId,
                    'reference_type' => 'we_ad_wallet',
                    'reference_id' => $adWallet->id,
                    'notes' => ['key' => 'wallet.notes.ad_funding'],
                ]);
                $wallet->refresh();
            }
        }

        return $this->book($adWallet, $viaGateway ? AdWalletEntry::TOPUP : AdWalletEntry::FUND, $split, +1, null, $key, $operationId);
    }

    /** Money paid straight into the ad wallet counts as withdrawable (a manual credit; the app goes through fromCheckout). */
    public function topup(User $user, Country $country, int $amountMinor, string $idempotencyKey): AdWalletEntry
    {
        $this->assertPositive($amountMinor);

        return DB::transaction(function () use ($user, $country, $amountMinor, $idempotencyKey) {
            $key = "we:topup:{$idempotencyKey}";
            if ($done = AdWalletEntry::query()->where('idempotency_key', $key)->first()) {
                return $done;
            }

            $adWallet = $this->lock($this->for($user, $country));

            return $this->book($adWallet, AdWalletEntry::TOPUP, ['reward' => 0, 'spend_only' => 0, 'withdrawable' => $amountMinor], +1, null, $key);
        });
    }

    /**
     * What returning this much of the free balance gives back to each bucket.
     *
     * @return array{reward: int, spend_only: int, withdrawable: int}
     */
    public function returnSplit(AdWallet $adWallet, int $amountMinor): array
    {
        $split = $this->take($this->free($adWallet), $amountMinor, self::RETURN_ORDER);
        if ($split === null) {
            throw new WatchEarnException('insufficient_ad_wallet', 422, [], ['free_minor' => $adWallet->freeMinor()]);
        }

        return $split;
    }

    /** WE-014 — free balance back to the wallet, each part into the bucket it came from. */
    public function returnToWallet(User $user, Country $country, int $amountMinor, string $idempotencyKey): AdWalletEntry
    {
        $this->assertPositive($amountMinor);

        return DB::transaction(function () use ($user, $country, $amountMinor, $idempotencyKey) {
            $key = "we:return:{$user->id}:{$idempotencyKey}";
            if ($done = AdWalletEntry::query()->where('idempotency_key', $key)->first()) {
                return $done;
            }

            $adWallet = $this->lock($this->for($user, $country));
            $split = $this->returnSplit($adWallet, $amountMinor);
            $entry = $this->book($adWallet, AdWalletEntry::RETURN, $split, -1, null, $key, $operationId = (string) Str::uuid());

            $wallet = $this->wallets->firstOrCreateWallet($user, $country);
            foreach ($split as $bucket => $part) {
                if ($part > 0) {
                    $this->wallets->credit($wallet, $part, WalletBucket::from($bucket), WalletTransactionType::AdReturn, [
                        'idempotency_key' => "{$key}:{$bucket}",
                        'operation_id' => $operationId,
                        'reference_type' => 'we_ad_wallet',
                        'reference_id' => $adWallet->id,
                        'notes' => ['key' => 'wallet.notes.ad_return'],
                    ]);
                }
            }

            return $entry;
        });
    }

    /** WE-022/WE-023 — lock part of the free balance for an ad (non-withdrawable first). Call inside a transaction. */
    public function reserve(Ad $ad, int $amountMinor): void
    {
        $this->assertPositive($amountMinor);

        $adWallet = $this->lock($ad->adWallet);
        $split = $this->take($this->free($adWallet), $amountMinor, self::SPEND_ORDER);
        if ($split === null) {
            throw new WatchEarnException('insufficient_ad_wallet', 422, [], ['free_minor' => $adWallet->freeMinor()]);
        }

        $this->book($adWallet, AdWalletEntry::RESERVE, $split, -1, $ad);

        $ad->forceFill([
            'reserved_reward_minor' => $ad->reserved_reward_minor + $split['reward'],
            'reserved_spend_only_minor' => $ad->reserved_spend_only_minor + $split['spend_only'],
            'reserved_withdrawable_minor' => $ad->reserved_withdrawable_minor + $split['withdrawable'],
            'budget_minor' => $ad->budget_minor + $amountMinor,
        ])->save();
    }

    /** An ad closed (rejected, stopped, ended): what's left of its budget goes back to the free balance. */
    public function release(Ad $ad): void
    {
        $split = ['reward' => $ad->reserved_reward_minor, 'spend_only' => $ad->reserved_spend_only_minor, 'withdrawable' => $ad->reserved_withdrawable_minor];
        if (array_sum($split) <= 0) {
            return;
        }

        $this->book($this->lock($ad->adWallet), AdWalletEntry::RELEASE, $split, +1, $ad);

        $ad->forceFill(['reserved_reward_minor' => 0, 'reserved_spend_only_minor' => 0, 'reserved_withdrawable_minor' => 0])->save();
    }

    /**
     * Spends from an ad's reserved budget, non-withdrawable first. The ad must be locked by the caller.
     *
     * @return array{reward: int, spend_only: int, withdrawable: int}|null null when the budget can't pay it
     */
    public function spend(Ad $ad, int $amountMinor): ?array
    {
        $split = $this->take($this->reserved($ad), $amountMinor, self::SPEND_ORDER);
        if ($split === null) {
            return null;
        }

        $ad->forceFill([
            'reserved_reward_minor' => $ad->reserved_reward_minor - $split['reward'],
            'reserved_spend_only_minor' => $ad->reserved_spend_only_minor - $split['spend_only'],
            'reserved_withdrawable_minor' => $ad->reserved_withdrawable_minor - $split['withdrawable'],
            'spent_minor' => $ad->spent_minor + $amountMinor,
        ]);

        return $split;
    }

    /** Puts a held part back into an ad's budget, into the buckets it came from (an unclaimed interaction share). */
    public function unspend(Ad $ad, array $split): void
    {
        $ad->forceFill([
            'reserved_reward_minor' => $ad->reserved_reward_minor + ($split['reward'] ?? 0),
            'reserved_spend_only_minor' => $ad->reserved_spend_only_minor + ($split['spend_only'] ?? 0),
            'reserved_withdrawable_minor' => $ad->reserved_withdrawable_minor + ($split['withdrawable'] ?? 0),
            'spent_minor' => max(0, $ad->spent_minor - array_sum($split)),
        ]);

        // A closed ad's budget is free again at once.
        if ($ad->status->closed()) {
            $ad->save();
            $this->release($ad);
        }
    }

    /**
     * Takes $amount from $pots in $order.
     *
     * @param  array<string, int>  $pots
     * @param  list<string>  $order
     * @return array{reward: int, spend_only: int, withdrawable: int}|null
     */
    public function take(array $pots, int $amount, array $order): ?array
    {
        if (array_sum($pots) < $amount) {
            return null;
        }

        $split = ['reward' => 0, 'spend_only' => 0, 'withdrawable' => 0];
        foreach ($order as $bucket) {
            $part = min($pots[$bucket] ?? 0, $amount);
            $split[$bucket] = $part;
            $amount -= $part;
        }

        return $split;
    }

    /** @return array{reward: int, spend_only: int, withdrawable: int} */
    public function free(AdWallet $adWallet): array
    {
        return ['reward' => $adWallet->reward_minor, 'spend_only' => $adWallet->spend_only_minor, 'withdrawable' => $adWallet->withdrawable_minor];
    }

    /** @return array{reward: int, spend_only: int, withdrawable: int} */
    public function reserved(Ad $ad): array
    {
        return ['reward' => $ad->reserved_reward_minor, 'spend_only' => $ad->reserved_spend_only_minor, 'withdrawable' => $ad->reserved_withdrawable_minor];
    }

    /** What the advertiser's ads hold right now, by bucket (locked until they close). */
    public function lockedIn(AdWallet $adWallet): array
    {
        $row = Ad::query()->where('ad_wallet_id', $adWallet->id)
            ->whereIn('status', array_map(fn (AdStatus $s) => $s->value, array_filter(AdStatus::cases(), fn (AdStatus $s) => $s->holdsBudget())))
            ->selectRaw('COALESCE(SUM(reserved_reward_minor),0) r, COALESCE(SUM(reserved_spend_only_minor),0) s, COALESCE(SUM(reserved_withdrawable_minor),0) w')
            ->first();

        return ['reward' => (int) $row->r, 'spend_only' => (int) $row->s, 'withdrawable' => (int) $row->w];
    }

    /** @param  array{reward: int, spend_only: int, withdrawable: int}  $split */
    private function book(AdWallet $adWallet, string $type, array $split, int $sign, ?Ad $ad = null, ?string $key = null, ?string $operationId = null): AdWalletEntry
    {
        $adWallet->forceFill([
            'reward_minor' => $adWallet->reward_minor + $sign * $split['reward'],
            'spend_only_minor' => $adWallet->spend_only_minor + $sign * $split['spend_only'],
            'withdrawable_minor' => $adWallet->withdrawable_minor + $sign * $split['withdrawable'],
        ])->save();

        return AdWalletEntry::query()->create([
            'ad_wallet_id' => $adWallet->id,
            'ad_id' => $ad?->id,
            'type' => $type,
            'reward_minor' => $split['reward'],
            'spend_only_minor' => $split['spend_only'],
            'withdrawable_minor' => $split['withdrawable'],
            'amount_minor' => array_sum($split),
            'free_after_minor' => $adWallet->freeMinor(),
            'operation_id' => $operationId,
            'idempotency_key' => $key,
            'created_at' => now(),
        ]);
    }

    private function lock(AdWallet $adWallet): AdWallet
    {
        return AdWallet::query()->lockForUpdate()->findOrFail($adWallet->id);
    }

    private function assertPositive(int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw new WatchEarnException('invalid_amount');
        }
    }
}
