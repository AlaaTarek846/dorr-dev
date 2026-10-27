<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Projects\FreelanceEarnings;

/** Hourly: freelancers' earnings whose waiting days passed go to their wallets (FL-505, `earnings.hold_days`). */
class ReleaseFreelanceEarnings extends Command
{
    protected $signature = 'freelance:release-earnings';

    protected $description = 'Move pending freelance earnings whose day came into the wallets (FL-505)';

    public function handle(FreelanceEarnings $earnings): int
    {
        $this->info($earnings->releaseDue().' earning(s) released.');

        return self::SUCCESS;
    }
}
