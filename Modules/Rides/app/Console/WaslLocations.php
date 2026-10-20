<?php

namespace Modules\Rides\Console;

use Illuminate\Console\Command;
use Modules\Rides\Services\Regulator\Wasl\WaslLocationService;

/** Every minute: the working drivers' last positions to Wasl. */
class WaslLocations extends Command
{
    protected $signature = 'rides:wasl-locations';

    protected $description = 'Send the working drivers\' locations to Wasl';

    public function handle(WaslLocationService $locations): int
    {
        $this->info($locations->send().' location(s) sent to Wasl.');

        return self::SUCCESS;
    }
}
