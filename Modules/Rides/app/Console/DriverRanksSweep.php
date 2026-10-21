<?php

namespace Modules\Rides\Console;

use Illuminate\Console\Command;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Models\Driver;
use Modules\Rides\Services\Drivers\DriverRanks;

/** Daily: every active captain's rank again — a grace that ran out, a probation that ended (D-5, D-253). */
class DriverRanksSweep extends Command
{
    protected $signature = 'rides:driver-ranks';

    protected $description = 'Work every active captain\'s rank out again (probation ends, warnings and their grace)';

    public function handle(DriverRanks $ranks): int
    {
        Driver::query()->where('status', DriverStatus::Active->value)->chunkById(200, function ($drivers) use ($ranks) {
            foreach ($drivers as $driver) {
                rescue(fn () => $ranks->evaluate($driver), report: true);
            }
        });

        return self::SUCCESS;
    }
}
