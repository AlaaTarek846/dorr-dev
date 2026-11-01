<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Market\FreelanceCapacity;

/** Every few minutes: a place kept for the first in line and not used in time goes to the next (FL-L10). */
class SweepFreelanceWaitlist extends Command
{
    protected $signature = 'freelance:sweep-waitlist';

    protected $description = 'Pass the unused places of the waiting lists to the next in line (FL-L10)';

    public function handle(FreelanceCapacity $capacity): int
    {
        $this->info($capacity->sweep().' place(s) offered.');

        return self::SUCCESS;
    }
}
