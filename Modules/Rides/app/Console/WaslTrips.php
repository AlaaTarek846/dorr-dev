<?php

namespace Modules\Rides\Console;

use Illuminate\Console\Command;
use Modules\Rides\Services\Regulator\Wasl\WaslTripService;

/** Every minute: completed rides due to be registered at Wasl (first try or a retry on Jawad's schedule). */
class WaslTrips extends Command
{
    protected $signature = 'rides:wasl-trips';

    protected $description = 'Register completed rides at Wasl and retry the ones that failed';

    public function handle(WaslTripService $trips): int
    {
        $due = $trips->due();
        $due->each(fn ($ride) => $trips->send($ride));
        $this->info("{$due->count()} ride(s) sent to Wasl.");

        return self::SUCCESS;
    }
}
