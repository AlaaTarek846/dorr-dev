<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use Illuminate\Support\Facades\DB;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\IdempotencyConflictException;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Exceptions\TransferException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Support\RequestHasher;

/**
 * User → user transfer inside one country (wallet-plan.md §10). Money movement
 * is WalletService::transfer() — this class only decides *whether* it may
 * happen: feature switch, per-transaction/day/month limits, a valid recipient,
 * and enough *available* (not held) balance.
 *
 * Whatever bucket the sender pays from, the recipient always receives
 * `spend_only` — that rule lives in WalletService::transfer() and can't be
 * bypassed from here, so a transferred amount can never be withdrawn, even if
 * it is sent straight back.
 */
class TransferService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TransferRecipientResolver $recipients,
        private readonly WalletNotifier $notifier,
    ) {}

    /**
     * Idempotent on `$idempotencyKey` (scoped per sender): the same key + same
     * request returns the original transfer without moving money twice or
     * re-checking limits; the same key with a different recipient/amount is a 409.
     *
     * @return array{out: WalletTransaction, in: WalletTransaction}
     *
     * @throws TransferException
     * @throws IdempotencyConflictException
     * @throws InsufficientBalanceException
     */
    public function send(User $sender, Country $country, string $recipientToken, int $amountMinor, ?WalletBucket $fromBucket, string $idempotencyKey): array
    {
        // Who is paid was fixed by the confirmation step; a missing/expired/foreign token stops here.
        $target = $this->recipients->resolveToken($sender, $country, $recipientToken);
        $recipient = $target['user'];

        $scopedKey = "transfer:user:{$sender->id}:{$idempotencyKey}";
        $requestHash = RequestHasher::hash([
            'sender' => $sender->id,
            'country_id' => $country->id,
            'recipient_user_id' => $recipient->id,
            'recipient_wallet_id' => $target['wallet']?->id,
            'amount_minor' => $amountMinor,
        ]);

        if ($replay = $this->replay($scopedKey, $requestHash, $idempotencyKey)) {
            return $replay;
        }

        $settings = WalletSetting::query()->where('country_id', $country->id)->first()
            ?? throw TransferException::settingsMissing();

        if (! $settings->transfers_enabled) {
            throw TransferException::disabled();
        }

        if ($settings->transfer_max_per_transaction_minor !== null && $amountMinor > $settings->transfer_max_per_transaction_minor) {
            throw TransferException::limitExceeded('per_transaction', $settings->transfer_max_per_transaction_minor, $settings->transfer_max_per_transaction_minor);
        }

        $senderWallet = Wallet::query()
            ->where('owner_type', 'user')->where('owner_id', $sender->id)->where('country_id', $country->id)
            ->first();

        // No wallet yet = nothing to send; same answer as an empty one.
        if ($senderWallet === null) {
            throw new InsufficientBalanceException(0, ($fromBucket ?? WalletBucket::Withdrawable)->value);
        }

        // Addressed by wallet number → exactly that wallet; by phone → their wallet in this country (created on first receipt).
        $recipientWallet = $target['wallet'] ?? $this->wallets->firstOrCreateWallet($recipient, $country);

        return DB::transaction(function () use ($senderWallet, $recipientWallet, $settings, $amountMinor, $fromBucket, $scopedKey, $requestHash, $idempotencyKey) {
            // Both wallets locked in ascending id order *before* anything is
            // read, so two people sending to each other at the same moment
            // can't deadlock, and the limit sums below can't be raced.
            $ids = [$senderWallet->id, $recipientWallet->id];
            sort($ids);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = Wallet::query()->lockForUpdate()->findOrFail($id);
            }
            $from = $locked[$senderWallet->id];
            $to = $locked[$recipientWallet->id];

            if ($replay = $this->replay($scopedKey, $requestHash, $idempotencyKey)) {
                return $replay;
            }

            $this->assertWithinPeriodLimits($from, $settings, $amountMinor);

            $bucket = $this->chooseBucket($from, $amountMinor, $fromBucket);

            $result = $this->wallets->transfer($from, $to, $amountMinor, $bucket, [
                'idempotency_key' => $scopedKey,
                'request_hash' => $requestHash,
                'reference_type' => 'transfer',
            ]);

            $this->notifier->transfer($from, $to, $amountMinor);

            return $result;
        });
    }

    /**
     * Day / month totals come from the ledger itself (the sender's `transfer_out`
     * rows), summed under the wallet lock — there is no counter to drift.
     */
    private function assertWithinPeriodLimits(Wallet $from, WalletSetting $settings, int $amountMinor): void
    {
        foreach ([
            ['per_day', $settings->transfer_max_per_day_minor, now()->startOfDay()],
            ['per_month', $settings->transfer_max_per_month_minor, now()->startOfMonth()],
        ] as [$scope, $limit, $since]) {
            if ($limit === null) {
                continue;
            }

            $sent = (int) WalletTransaction::query()
                ->where('wallet_id', $from->id)
                ->where('type', WalletTransactionType::TransferOut)
                ->where('created_at', '>=', $since)
                ->sum('amount_minor');

            if ($sent + $amountMinor > $limit) {
                throw TransferException::limitExceeded($scope, $limit, $limit - $sent);
            }
        }
    }

    /**
     * Debit checks in WalletService only guard spend_only ≥ 0; holds are not
     * its concern — so "available" (balance − held) is enforced here, and a
     * transfer can never dip into money reserved for a withdrawal or a service.
     * With no explicit choice, bonus money (spend_only) is spent first.
     */
    private function chooseBucket(Wallet $from, int $amountMinor, ?WalletBucket $requested): WalletBucket
    {
        $candidates = $requested !== null ? [$requested] : [WalletBucket::SpendOnly, WalletBucket::Withdrawable];

        foreach ($candidates as $bucket) {
            if ($from->availableMinor($bucket) >= $amountMinor) {
                return $bucket;
            }
        }

        throw new InsufficientBalanceException($from->id, ($requested ?? WalletBucket::Withdrawable)->value);
    }

    /**
     * @return array{out: WalletTransaction, in: WalletTransaction}|null
     */
    private function replay(string $scopedKey, string $requestHash, string $idempotencyKey): ?array
    {
        $out = WalletTransaction::query()->where('idempotency_key', $scopedKey)->first();

        if ($out === null) {
            return null;
        }

        if ($out->request_hash !== $requestHash) {
            throw new IdempotencyConflictException($idempotencyKey);
        }

        return [
            'out' => $out,
            'in' => WalletTransaction::query()
                ->where('operation_id', $out->operation_id)
                ->where('type', WalletTransactionType::TransferIn)
                ->firstOrFail(),
        ];
    }
}
