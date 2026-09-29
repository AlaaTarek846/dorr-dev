<?php

namespace Modules\Wallet\Console;

use Illuminate\Console\Command;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Models\PaymentTransaction;

/**
 * Marks payments the customer abandoned as `expired` once past expires_at.
 * Expired is not final: a late gateway confirmation can still be completed by
 * an admin reconcile (PaymentTransactionStatus::canBeCompleted).
 */
class ExpireStalePayments extends Command
{
    protected $signature = 'payment:expire-stale';

    protected $description = 'Marks pending online payments past their expiry as expired.';

    public function handle(): int
    {
        $expired = PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => PaymentTransactionStatus::Expired, 'updated_at' => now()]);

        $this->info("Expired {$expired} stale payment(s).");

        return self::SUCCESS;
    }
}
