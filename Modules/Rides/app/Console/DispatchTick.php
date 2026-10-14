<?php

namespace Modules\Rides\Console;

use Illuminate\Console\Command;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Lifecycle\RideChangeService;
use Modules\Rides\Services\Safety\SafetyCheckService;
use Modules\Rides\Services\Scheduling\ScheduledRideService;

/** Every 10 seconds: scheduled rides whose time came, expired offers out, the next dispatch round for rides still searching, long searches expired. */
class DispatchTick extends Command
{
    protected $signature = 'rides:dispatch-tick';

    protected $description = 'Run the next dispatch round for rides that are still searching for a driver';

    public function handle(DispatchEngine $dispatch, ScheduledRideService $scheduled, RideChangeService $changes, SafetyCheckService $checks): int
    {
        $changes->expireDue();
        $checks->tick();
        $started = $scheduled->tick();
        if ($started > 0) {
            $this->info("{$started} scheduled ride(s) started.");
        }
        $count = $dispatch->tick();
        $this->info("{$count} ride(s) dispatched.");

        return self::SUCCESS;
    }
}
