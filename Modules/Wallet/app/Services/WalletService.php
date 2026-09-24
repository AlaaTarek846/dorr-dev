<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletDirection;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\IdempotencyConflictException;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Exceptions\WalletHoldException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Support\OwnerType;
use Modules\Wallet\Support\RequestHasher;

/**
 * The only door to any wallet balance change — docs/wallet-structure.md §0,
 * invariant #2 (fصل 10 في wallet-plan.md). Nothing outside this class should
 * ever write to wallets.*_minor or create a wallet_transactions/wallet_holds
 * row directly.
 *
 * `$meta` accepted by most methods is a whitelisted bag of optional columns:
 * reference_type, reference_id, notes, created_by_type, created_by_id,
 * fee_rule_id, fee_percent, payment_transaction_id, idempotency_key,
 * request_hash, operation_id.
 */
class WalletService
{
    /**
     * Wallets are created lazily, on first top-up/receipt — not at
     * registration (docs/wallet-plan.md §7). This is the only place a
     * wallets row is ever inserted; currency_id is snapshotted from the
     * country at creation time and never changes afterward.
     */
    public function firstOrCreateWallet(Model $owner, Country $country): Wallet
    {
        return DB::transaction(fn () => Wallet::query()->firstOrCreate(
            [
                'owner_type' => OwnerType::aliasFor($owner),
                'owner_id' => $owner->getKey(),
                'country_id' => $country->id,
            ],
            [
                'currency_id' => $country->currency_id,
            ],
        ));
    }

    public function credit(Wallet $wallet, int $amountMinor, WalletBucket $bucket, WalletTransactionType $type, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amountMinor, $bucket, $type, $meta) {
            return $this->post($this->lock($wallet), WalletDirection::Credit, $bucket, $amountMinor, $type, $meta);
        });
    }

    public function debit(Wallet $wallet, int $amountMinor, WalletBucket $bucket, WalletTransactionType $type, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amountMinor, $bucket, $type, $meta) {
            return $this->post($this->lock($wallet), WalletDirection::Debit, $bucket, $amountMinor, $type, $meta);
        });
    }

    /**
     * The receiving side is always spend_only, always — not a parameter, on
     * purpose (docs/wallet-plan.md §10). `$fromBucket` is the sender's
     * choice of which of their own buckets to spend from.
     *
     * @return array{out: WalletTransaction, in: WalletTransaction}
     */
    public function transfer(Wallet $from, Wallet $to, int $amountMinor, WalletBucket $fromBucket, array $meta = []): array
    {
        return DB::transaction(function () use ($from, $to, $amountMinor, $fromBucket, $meta) {
            [$lockedFrom, $lockedTo] = $this->lockPair($from, $to);

            $operationId = $meta['operation_id'] ?? (string) Str::uuid();

            $out = $this->post($lockedFrom, WalletDirection::Debit, $fromBucket, $amountMinor, WalletTransactionType::TransferOut, [
                ...$meta,
                'operation_id' => $operationId,
                'counterparty_wallet_id' => $lockedTo->id,
            ]);

            // Use the persisted row's own operation_id, not our freshly
            // generated one — on an idempotent replay $out is the *original*
            // row, whose operation_id was fixed on the first call.
            $realOperationId = $out->operation_id;

            $existingIn = WalletTransaction::query()
                ->where('operation_id', $realOperationId)
                ->where('type', WalletTransactionType::TransferIn)
                ->first();

            if ($existingIn !== null) {
                return ['out' => $out, 'in' => $existingIn];
            }

            $in = $this->post($lockedTo, WalletDirection::Credit, WalletBucket::SpendOnly, $amountMinor, WalletTransactionType::TransferIn, [
                'operation_id' => $realOperationId,
                'counterparty_wallet_id' => $lockedFrom->id,
                'reference_type' => $meta['reference_type'] ?? null,
                'reference_id' => $meta['reference_id'] ?? null,
                'notes' => $meta['notes'] ?? null,
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * Writes a new opposite-direction row pointing back at the original —
     * the original is never touched (WalletTransaction enforces this too).
     */
    public function reverse(WalletTransaction $original, string $reasonKey, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($original, $reasonKey, $meta) {
            if ($original->isReversed()) {
                throw new LogicException("wallet_transactions #{$original->id} was already reversed.");
            }

            $wallet = $this->lock($original->wallet()->firstOrFail());
            $oppositeDirection = $original->direction === WalletDirection::Credit
                ? WalletDirection::Debit
                : WalletDirection::Credit;

            return $this->post($wallet, $oppositeDirection, $original->bucket, $original->amount_minor, WalletTransactionType::Reversal, [
                ...$meta,
                'reverses_transaction_id' => $original->id,
                'reference_type' => $original->reference_type,
                'reference_id' => $original->reference_id,
                'notes' => $meta['notes'] ?? ['key' => 'wallet.notes.reversal', 'variables' => ['reason' => $reasonKey]],
            ]);
        });
    }

    /**
     * Admin-only balance correction. The bucket and reason are mandatory —
     * there is no default bucket, same as every other wallet_transactions
     * write (docs/wallet-structure.md §2).
     */
    public function manualAdjustment(
        Wallet $wallet,
        int $amountMinor,
        WalletBucket $bucket,
        WalletDirection $direction,
        string $reasonKey,
        string $adminOwnerType,
        int $adminId,
    ): WalletTransaction {
        return DB::transaction(function () use ($wallet, $amountMinor, $bucket, $direction, $reasonKey, $adminOwnerType, $adminId) {
            return $this->post($this->lock($wallet), $direction, $bucket, $amountMinor, WalletTransactionType::ManualAdjustment, [
                'notes' => ['key' => 'wallet.notes.manual_adjustment', 'variables' => ['reason' => $reasonKey]],
                'created_by_type' => $adminOwnerType,
                'created_by_id' => $adminId,
            ]);
        });
    }

    /**
     * Reserves an amount without moving any money — no wallet_transactions
     * row is created here (docs/wallet-structure.md §2.1).
     */
    public function hold(Wallet $wallet, int $amountMinor, WalletBucket $bucket, array $meta = []): WalletHold
    {
        return DB::transaction(function () use ($wallet, $amountMinor, $bucket, $meta) {
            $this->assertPositiveAmount($amountMinor);

            $idempotencyKey = $meta['idempotency_key'] ?? null;

            if ($idempotencyKey !== null) {
                $existing = WalletHold::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $locked = $this->lock($wallet);

            if ($amountMinor > $locked->availableMinor($bucket)) {
                throw new InsufficientBalanceException($locked->id, $bucket->value);
            }

            $hold = WalletHold::query()->create([
                'uuid' => (string) Str::uuid(),
                'wallet_id' => $locked->id,
                'bucket' => $bucket,
                'amount_minor' => $amountMinor,
                'status' => WalletHoldStatus::Active,
                'reference_type' => $meta['reference_type'] ?? null,
                'reference_id' => $meta['reference_id'] ?? null,
                'reason_code' => $meta['reason_code'] ?? null,
                'expires_at' => $meta['expires_at'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->adjustHeld($locked, $bucket, $amountMinor);

            return $hold;
        });
    }

    /**
     * Posts the real wallet_transactions debit for the actual amount (which
     * may be less than the hold) and closes the hold — the unclaimed
     * difference becomes available again the moment `held_*_minor` drops,
     * no separate "refund" step needed.
     */
    public function capture(WalletHold $hold, int $capturedAmountMinor, WalletTransactionType $type, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($hold, $capturedAmountMinor, $type, $meta) {
            $lockedHold = $this->lockHold($hold);
            $this->assertActive($lockedHold);

            if ($capturedAmountMinor <= 0 || $capturedAmountMinor > $lockedHold->amount_minor) {
                throw new WalletHoldException(
                    "wallet_holds #{$lockedHold->id}: captured amount must be between 1 and the held amount ({$lockedHold->amount_minor} minor units).",
                );
            }

            $wallet = $this->lock($lockedHold->wallet()->firstOrFail());

            $transaction = $this->post($wallet, WalletDirection::Debit, $lockedHold->bucket, $capturedAmountMinor, $type, [
                ...$meta,
                'reference_type' => $meta['reference_type'] ?? $lockedHold->reference_type,
                'reference_id' => $meta['reference_id'] ?? $lockedHold->reference_id,
            ]);

            // The whole hold closes here — releasing the full original
            // amount, not just what was captured, is what makes any
            // unclaimed difference available again automatically.
            $this->adjustHeld($wallet, $lockedHold->bucket, -$lockedHold->amount_minor);

            $lockedHold->update([
                'status' => WalletHoldStatus::Captured,
                'captured_amount_minor' => $capturedAmountMinor,
                'wallet_transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }

    /**
     * Frees a hold with no financial movement at all — nothing happened, so
     * there's nothing to post or refund.
     */
    public function release(WalletHold $hold, string $reasonKey): WalletHold
    {
        return DB::transaction(function () use ($hold, $reasonKey) {
            $lockedHold = $this->lockHold($hold);
            $this->assertActive($lockedHold);

            $wallet = $this->lock($lockedHold->wallet()->firstOrFail());
            $this->adjustHeld($wallet, $lockedHold->bucket, -$lockedHold->amount_minor);

            $lockedHold->update(['status' => WalletHoldStatus::Released]);

            return $lockedHold;
        });
    }

    /**
     * Auto-releases holds past their expires_at — called from the
     * wallet:reconcile command (Modules\Wallet\Console\ReconcileWallets).
     * Holds with a null expires_at are intentionally excluded: they're
     * resolved by a business decision (e.g. admin approve/reject on a
     * withdrawal request), not a timer — docs/wallet-structure.md §2.1.
     */
    public function releaseExpiredHolds(): int
    {
        $expired = WalletHold::query()
            ->where('status', WalletHoldStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $hold) {
            $this->release($hold, 'expired');
        }

        return $expired->count();
    }

    private function post(
        Wallet $wallet,
        WalletDirection $direction,
        WalletBucket $bucket,
        int $amountMinor,
        WalletTransactionType $type,
        array $meta,
    ): WalletTransaction {
        $this->assertPositiveAmount($amountMinor);

        $idempotencyKey = $meta['idempotency_key'] ?? null;
        $requestHash = $meta['request_hash'] ?? null;

        if ($idempotencyKey !== null) {
            $requestHash ??= RequestHasher::hash([
                'wallet_id' => $wallet->id,
                'direction' => $direction->value,
                'bucket' => $bucket->value,
                'amount_minor' => $amountMinor,
                'type' => $type->value,
                'reference_type' => $meta['reference_type'] ?? null,
                'reference_id' => $meta['reference_id'] ?? null,
            ]);

            $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new IdempotencyConflictException($idempotencyKey);
                }

                return $existing;
            }
        }

        $signedAmount = $direction === WalletDirection::Credit ? $amountMinor : -$amountMinor;
        $newBucketBalance = $wallet->balanceMinor($bucket) + $signedAmount;

        // spend_only can never go negative. withdrawable can (allowed debt
        // is a WalletEligibilityService concern for a later phase, not this
        // layer) — docs/wallet-structure.md §11 invariant #8.
        if ($bucket === WalletBucket::SpendOnly && $newBucketBalance < 0) {
            throw new InsufficientBalanceException($wallet->id, $bucket->value);
        }

        $withdrawableMinor = $bucket === WalletBucket::Withdrawable ? $newBucketBalance : $wallet->withdrawable_minor;
        $spendOnlyMinor = $bucket === WalletBucket::SpendOnly ? $newBucketBalance : $wallet->spend_only_minor;

        $transaction = WalletTransaction::query()->create([
            'uuid' => (string) Str::uuid(),
            'wallet_id' => $wallet->id,
            'operation_id' => $meta['operation_id'] ?? (string) Str::uuid(),
            'direction' => $direction,
            'bucket' => $bucket,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency_id' => $wallet->currency_id,
            'country_id' => $wallet->country_id,
            'balance_after_minor' => $newBucketBalance,
            'total_balance_after_minor' => $withdrawableMinor + $spendOnlyMinor,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'counterparty_wallet_id' => $meta['counterparty_wallet_id'] ?? null,
            'reverses_transaction_id' => $meta['reverses_transaction_id'] ?? null,
            'fee_rule_id' => $meta['fee_rule_id'] ?? null,
            'fee_percent' => $meta['fee_percent'] ?? null,
            'payment_transaction_id' => $meta['payment_transaction_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'notes' => $meta['notes'] ?? null,
            'created_by_type' => $meta['created_by_type'] ?? null,
            'created_by_id' => $meta['created_by_id'] ?? null,
        ]);

        $wallet->update([
            'withdrawable_minor' => $withdrawableMinor,
            'spend_only_minor' => $spendOnlyMinor,
        ]);

        return $transaction;
    }

    private function adjustHeld(Wallet $wallet, WalletBucket $bucket, int $deltaMinor): void
    {
        $column = $bucket === WalletBucket::Withdrawable ? 'held_withdrawable_minor' : 'held_spend_only_minor';

        $wallet->update([$column => $wallet->{$column} + $deltaMinor]);
    }

    private function assertActive(WalletHold $hold): void
    {
        if ($hold->status !== WalletHoldStatus::Active) {
            throw new WalletHoldException("wallet_holds #{$hold->id} is {$hold->status->value}, not active.");
        }
    }

    private function assertPositiveAmount(int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('amount_minor must be greater than zero.');
        }
    }

    private function lock(Wallet $wallet): Wallet
    {
        return Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
    }

    private function lockHold(WalletHold $hold): WalletHold
    {
        return WalletHold::query()->lockForUpdate()->findOrFail($hold->id);
    }

    /**
     * Locks both wallets in ascending id order regardless of which is
     * "from"/"to" — the deadlock-avoidance rule from wallet-plan.md §10 /
     * wallet-structure.md §0, generalized to any operation touching two
     * wallets at once.
     *
     * @return array{0: Wallet, 1: Wallet} locked versions of [$a, $b], same order as given
     */
    private function lockPair(Wallet $a, Wallet $b): array
    {
        $ids = [$a->id, $b->id];
        sort($ids);

        $locked = collect($ids)
            ->mapWithKeys(fn (int $id) => [$id => Wallet::query()->lockForUpdate()->findOrFail($id)]);

        return [$locked[$a->id], $locked[$b->id]];
    }
}
