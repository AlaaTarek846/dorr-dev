<?php

namespace Modules\Driver\Console;

use Illuminate\Console\Command;
use Modules\Driver\Services\PresenceService;

/** Every minute: a driver whose phone stopped sending goes offline; an ended pause is online again. */
class SweepDriverPresence extends Command
{
    protected $signature = 'drivers:presence-sweeper';

    protected $description = 'Take drivers offline when their phone stopped sending, and end finished pauses';

    public function handle(PresenceService $presence): int
    {
        $count = $presence->sweepStale((int) config('driver.stale_after_s', 900));
        $this->info("{$count} driver(s) taken offline.");

        return self::SUCCESS;
    }
}
