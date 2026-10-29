<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Projects\Disputes;

/** Hourly: a dispute decision whose appeal days are over, not appealed, moves the money (FL-507). */
class ExecuteDisputeDecisions extends Command
{
    protected $signature = 'freelance:execute-disputes';

    protected $description = 'Run the dispute decisions whose appeal time is over (FL-507)';

    public function handle(Disputes $disputes): int
    {
        $this->info($disputes->executeDue().' dispute decision(s) run.');

        return self::SUCCESS;
    }
}
