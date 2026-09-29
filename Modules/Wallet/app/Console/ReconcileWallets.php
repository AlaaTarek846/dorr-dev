<?php

namespace Modules\Wallet\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletDirection;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;

/**
 * Recomputes every wallet's balance from wallet_transactions (the source of
 * truth) and flags any drift from the stored projection — docs/wallet-plan.md
 * §11. Also auto-releases wallet_holds past their expires_at.
 *
 * Meant to run on a schedule; a mismatch here is always a bug, never
 * expected — investigate immediately rather than re-running.
 */
class ReconcileWallets extends Command
{
    protected $signature = 'wallet:reconcile';

    protected $description = "Recomputes wallet balances from wallet_transactions and flags any mismatch; releases expired wallet_holds.";

    public function handle(WalletService $wallets): int
    {
        $mismatches = 0;

        Wallet::query()->orderBy('id')->chunkById(200, function ($chunk) use (&$mismatches) {
            foreach ($chunk as $wallet) {
                $computedWithdrawable = $this->sumBucket($wallet->id, WalletBucket::Withdrawable);
                $computedSpendOnly = $this->sumBucket($wallet->id, WalletBucket::SpendOnly);

                if ($computedWithdrawable !== $wallet->withdrawable_minor || $computedSpendOnly !== $wallet->spend_only_minor) {
                    $mismatches++;

                    Log::error('wallet:reconcile mismatch', [
                        'wallet_id' => $wallet->id,
                        'stored' => [
                            'withdrawable_minor' => $wallet->withdrawable_minor,
                            'spend_only_minor' => $wallet->spend_only_minor,
                        ],
                        'computed' => [
                            'withdrawable_minor' => $computedWithdrawable,
                            'spend_only_minor' => $computedSpendOnly,
                        ],
                    ]);

                    $this->error(
                        "Wallet #{$wallet->id}: mismatch — stored withdrawable={$wallet->withdrawable_minor} computed={$computedWithdrawable}; ".
                        "stored spend_only={$wallet->spend_only_minor} computed={$computedSpendOnly}",
                    );

                    continue;
                }

                $wallet->update(['last_reconciled_at' => now()]);
            }
        });

        $released = $wallets->releaseExpiredHolds();

        $this->info("Reconciled wallets. Mismatches: {$mismatches}. Expired holds released: {$released}.");

        return $mismatches === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sumBucket(int $walletId, WalletBucket $bucket): int
    {
        $credits = (int) WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('bucket', $bucket)
            ->where('direction', WalletDirection::Credit)
            ->sum('amount_minor');

        $debits = (int) WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('bucket', $bucket)
            ->where('direction', WalletDirection::Debit)
            ->sum('amount_minor');

        return $credits - $debits;
    }
}
