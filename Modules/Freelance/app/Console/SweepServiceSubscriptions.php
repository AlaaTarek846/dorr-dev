<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Market\ServiceSubscriptions;

/** Hourly: remind clients to confirm the next month of a service subscription, and end the ones asked to end or not confirmed (FL-L05). */
class SweepServiceSubscriptions extends Command
{
    protected $signature = 'freelance:sweep-subscriptions';

    protected $description = 'Remind the next month of service subscriptions and end the ones that are over (FL-L05)';

    public function handle(ServiceSubscriptions $subscriptions): int
    {
        $done = $subscriptions->sweep();
        $this->info("{$done['reminded']} reminded, {$done['ended']} ended.");

        return self::SUCCESS;
    }
}
