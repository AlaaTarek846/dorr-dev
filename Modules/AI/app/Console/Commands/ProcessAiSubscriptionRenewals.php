<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Services\AiSubscriptionPurchaseService;

/**
 * Professional AI subscription billing (2026-09-29): the daily sweep that
 * actually makes auto-renewal real. See
 * AiSubscriptionPurchaseService::processDueSubscriptions() for the full
 * logic (renew, open a 3-day grace window on a failed charge, suspend once
 * grace has passed, expire a non-auto-renewing subscription past its
 * ends_at) - this command is a thin, testable wrapper around it.
 */
class ProcessAiSubscriptionRenewals extends Command
{
    protected $signature = 'ai:process-subscription-renewals';

    protected $description = 'Renew due AI subscriptions from the owner\'s wallet, open/close grace periods, and expire/suspend as needed.';

    public function __construct(protected AiSubscriptionPurchaseService $subscriptions)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stats = $this->subscriptions->processDueSubscriptions();

        $this->table(['Renewed', 'Grace opened', 'Suspended', 'Expired'], [[
            $stats['renewed'],
            $stats['grace_opened'],
            $stats['suspended'],
            $stats['expired'],
        ]]);

        return self::SUCCESS;
    }
}
