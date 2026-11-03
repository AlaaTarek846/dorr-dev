<?php

namespace Modules\WatchEarn\Console;

use Illuminate\Console\Command;
use Modules\WatchEarn\Services\AdService;
use Modules\WatchEarn\Services\RewardService;
use Modules\WatchEarn\Services\ViewService;

/**
 * The server's own clock for Watch & Earn (the apps never poll): ads past their end date close and free their
 * budget, interaction windows that closed settle their held share, and rewards whose wait is over reach wallets.
 */
class WatchEarnTick extends Command
{
    protected $signature = 'watch-earn:tick';

    protected $description = 'Closes ended ads, settles closed interaction windows and releases due rewards.';

    public function handle(AdService $ads, ViewService $views, RewardService $rewards): int
    {
        $ended = $ads->endExpired();
        $settled = $views->settleExpired();
        $released = $rewards->releaseDue();

        $this->info("Ads ended: {$ended}. Windows settled: {$settled}. Rewards released: {$released}.");

        return self::SUCCESS;
    }
}
