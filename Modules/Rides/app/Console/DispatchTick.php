<?php

namespace Modules\Rides\Console;

use Illuminate\Console\Command;
use Modules\Rides\Services\Airport\AirportQueue;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Lifecycle\RideChangeService;
use Modules\Rides\Services\Lifecycle\WaitingAlerts;
use Modules\Rides\Services\Safety\SafetyCheckService;
use Modules\Rides\Services\Scheduling\ScheduledRideService;

/** Every 10 seconds: scheduled rides whose time came, expired offers out, the next dispatch round for rides still searching, long searches expired, "free waiting ends soon". */
class DispatchTick extends Command
{
    protected $signature = 'rides:dispatch-tick';

    protected $description = 'Run the next dispatch round for rides that are still searching for a driver';

    public function handle(DispatchEngine $dispatch, ScheduledRideService $scheduled, RideChangeService $changes, SafetyCheckService $checks, WaitingAlerts $waiting, AirportQueue $queue): int
    {
        $queue->sweep();
        $changes->expireDue();
        $checks->tick();
        $waiting->tick();
        // Block 06: a booking whose captain may be late — the rider hears early (AI-10 / AI-17 alongside).
        app(\Modules\Rides\Services\Scheduling\LateRisk::class)->tick();
        app(\Modules\Rides\Services\Ai\CancelRisk::class)->tick();
        $started = $scheduled->tick();
        if ($started > 0) {
            $this->info("{$started} scheduled ride(s) started.");
        }
        $count = $dispatch->tick();
        // O-47: «is the scheduler running?» — the diagnostics read this heartbeat.
        \Illuminate\Support\Facades\Cache::put(\Modules\Rides\Services\Dispatch\DispatchDiagnostics::TICK_KEY, now()->toIso8601String(), 3600);
        $this->info("{$count} ride(s) dispatched.");

        return self::SUCCESS;
    }
}
