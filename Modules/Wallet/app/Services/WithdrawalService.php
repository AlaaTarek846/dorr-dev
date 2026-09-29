<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Enums\WithdrawalRequestStatus;
use Modules\Wallet\Exceptions\IdempotencyConflictException;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Exceptions\WithdrawalException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Models\WithdrawalMethod;
use Modules\Wallet\Models\WithdrawalRequest;
use Modules\Wallet\Support\OwnerType;
use Modules\Wallet\Support\RequestHasher;

/**
 * A thin layer over WalletService's hold/capture/release — there is no
 * balance logic of its own here (docs/wallet-tasks.md Phase 8):
 *
 *   request  → hold the amount (withdrawable bucket) *immediately*, not at approval
 *   approve  → capture the hold into a real `withdrawal` ledger row
 *   reject   → release the hold, no money moves
 *
 * Only `withdrawable` money can ever be withdrawn; spend_only never reaches a hold.
 */
class WithdrawalService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletNotifier $notifier,
    ) {}

    /**
     * Idempotent on `$idempotencyKey` (scoped per owner). One pending request
     * per wallet: the wallet row is locked first, so two simultaneous taps
     * can't both pass the "no pending request" check.
     *
     * @throws WithdrawalException
     * @throws IdempotencyConflictException
     * @throws InsufficientBalanceException
     */
    public function request(Model $owner, Country $country, WithdrawalMethod $method, int $amountMinor, string $idempotencyKey): WithdrawalRequest
    {
        $alias = OwnerType::aliasFor($owner);
        $scopedKey = "{$alias}:{$owner->getKey()}:{$idempotencyKey}";
        $requestHash = RequestHasher::hash([
            'owner' => "{$alias}:{$owner->getKey()}",
            'country_id' => $country->id,
            'withdrawal_method_id' => $method->id,
            'amount_minor' => $amountMinor,
        ]);

        if ($existing = $this->replayable($scopedKey, $requestHash, $idempotencyKey)) {
            return $existing;
        }

        if (! $method->status || $method->owner_type !== $alias || (int) $method->owner_id !== (int) $owner->getKey()) {
            throw WithdrawalException::methodUnavailable();
        }

        $settings = WalletSetting::query()->where('country_id', $country->id)->first()
            ?? throw WithdrawalException::settingsMissing();

        $min = $settings->min_withdrawal_minor;
        $max = $settings->max_withdrawal_minor;

        if ($amountMinor <= 0 || ($min !== null && $amountMinor < $min) || ($max !== null && $amountMinor > $max)) {
            throw WithdrawalException::amountOutOfRange($min, $max);
        }

        $wallet = Wallet::query()
            ->where('owner_type', $alias)
            ->where('owner_id', $owner->getKey())
            ->where('country_id', $country->id)
            ->first();

        // No wallet yet = nothing to withdraw; same answer as an empty one.
        if ($wallet === null) {
            throw new InsufficientBalanceException(0, WalletBucket::Withdrawable->value);
        }

        return DB::transaction(function () use ($wallet, $method, $amountMinor, $scopedKey, $requestHash, $idempotencyKey) {
            Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

            // Re-check under the lock: a concurrent identical request may have just finished.
            if ($existing = $this->replayable($scopedKey, $requestHash, $idempotencyKey)) {
                return $existing;
            }

            $pending = WithdrawalRequest::query()
                ->where('wallet_id', $wallet->id)
                ->where('status', WithdrawalRequestStatus::Pending)
                ->exists();

            if ($pending) {
                throw WithdrawalException::pendingExists();
            }

            // expires_at = null on purpose: an admin decides this, not a timer.
            $hold = $this->wallets->hold($wallet, $amountMinor, WalletBucket::Withdrawable, [
                'reason_code' => 'withdrawal_request',
                'idempotency_key' => "withdrawal:{$scopedKey}",
            ]);

            $request = WithdrawalRequest::query()->create([
                'wallet_id' => $wallet->id,
                'withdrawal_method_id' => $method->id,
                'amount_minor' => $amountMinor,
                'status' => WithdrawalRequestStatus::Pending,
                'hold_id' => $hold->id,
                'idempotency_key' => $scopedKey,
                'request_hash' => $requestHash,
            ]);

            // The hold couldn't point at the request before the request existed.
            $hold->update(['reference_type' => 'withdrawal_request', 'reference_id' => $request->id]);

            $this->notifier->withdrawalRequested($request);

            return $request;
        });
    }

    /**
     * Pays out: the hold becomes a real ledger debit and the receipt is stored.
     * The receipt is mandatory — an approval nobody can prove is a liability.
     *
     * @throws WithdrawalException
     */
    public function approve(WithdrawalRequest $request, UploadedFile $receipt, ?string $note, int $adminId): WithdrawalRequest
    {
        return DB::transaction(function () use ($request, $receipt, $note, $adminId) {
            $locked = $this->lockPending($request);

            $this->wallets->capture($locked->hold, $locked->hold->amount_minor, WalletTransactionType::Withdrawal, [
                'notes' => ['key' => 'wallet.notes.withdrawal', 'variables' => []],
                'created_by_type' => 'admin',
                'created_by_id' => $adminId,
            ]);

            $locked->update([
                'status' => WithdrawalRequestStatus::Approved,
                'note' => $note,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);
            $locked->setSingleMedia('receipt', $receipt);

            $this->notifier->withdrawalPaid($locked);

            return $locked;
        });
    }

    /**
     * @throws WithdrawalException
     */
    public function reject(WithdrawalRequest $request, string $reason, int $adminId): WithdrawalRequest
    {
        return DB::transaction(function () use ($request, $reason, $adminId) {
            $locked = $this->lockPending($request);

            $this->wallets->release($locked->hold, 'withdrawal_rejected');

            $locked->update([
                'status' => WithdrawalRequestStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);

            $this->notifier->withdrawalRejected($locked, $reason);

            return $locked;
        });
    }

    /**
     * Locks the request and refuses anything already decided, so an approve
     * and a reject racing each other can't both win.
     */
    private function lockPending(WithdrawalRequest $request): WithdrawalRequest
    {
        $locked = WithdrawalRequest::query()->lockForUpdate()->with('hold')->findOrFail($request->id);

        if ($locked->status !== WithdrawalRequestStatus::Pending) {
            throw WithdrawalException::notPending();
        }

        return $locked;
    }

    private function replayable(string $scopedKey, string $requestHash, string $idempotencyKey): ?WithdrawalRequest
    {
        $existing = WithdrawalRequest::query()->where('idempotency_key', $scopedKey)->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->request_hash !== $requestHash) {
            throw new IdempotencyConflictException($idempotencyKey);
        }

        return $existing;
    }
}
