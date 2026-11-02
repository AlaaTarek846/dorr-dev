<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\Verification\VerificationBadgeService;
use Modules\Chat\Services\Verification\VerificationRequestService;

/**
 * Account verification deadlines (AV-018): warns both sides once before a request's deadline, and puts a
 * request on hold once its deadline passed so the staff decide (extend, ask for more, close). Server-side
 * only — the apps never ask on a timer. Also expires the badges past their end. Scheduled every five minutes.
 */
class SweepVerificationDeadlines extends Command
{
    protected $signature = 'chat:verification-deadlines';

    protected $description = 'Warn before verification request deadlines and hold the requests whose deadline passed';

    public function handle(VerificationRequestService $requests, VerificationBadgeService $badges): int
    {
        $done = $requests->sweepDeadlines();
        // Badges past their end (AV-025).
        $expired = $badges->expireDue();
        $this->info("{$done['warned']} warned, {$done['suspended']} put on hold, {$expired} badge(s) expired.");

        return self::SUCCESS;
    }
}
