<?php

namespace Modules\Rides\Console;

use Illuminate\Console\Command;
use Modules\Rides\Services\Settlement\RideSettlementService;

/** Hourly: online-paid earnings whose waiting days passed go to the driver's wallet (`settlement.pending_days`). */
class ReleaseEarnings extends Command
{
    protected $signature = 'rides:release-earnings';

    protected $description = 'Move pending ride earnings whose release time passed into the drivers\' wallets';

    public function handle(RideSettlementService $settlement): int
    {
        $count = $settlement->releaseDue();
        $this->info("{$count} earning(s) released.");

        return self::SUCCESS;
    }
}
