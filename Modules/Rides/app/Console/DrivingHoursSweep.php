<?php

namespace Modules\Rides\Console;

use Illuminate\Console\Command;
use Modules\Rides\Services\Drivers\DrivingHours;

/** Every minute: the rest reminder and the mandatory driving-time limit (`driving.warn_s`, `driving.max_s`; D-42, D-43). */
class DrivingHoursSweep extends Command
{
    protected $signature = 'rides:driving-hours';

    protected $description = 'Remind captains to rest and take those over the mandatory driving limit offline';

    public function handle(DrivingHours $hours): int
    {
        $count = $hours->sweep();
        $this->info("{$count} captain(s) told.");

        return self::SUCCESS;
    }
}
