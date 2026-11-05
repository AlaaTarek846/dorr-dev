<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Projects\ChangeRequests;

/** Hourly (FS-101): reminds the side that has to answer a change request a day before it runs out - once. */
class RemindChangeRequests extends Command
{
    protected $signature = 'freelance:change-reminders';

    protected $description = 'Remind who has to answer an agreement change request that is about to expire (FS-101)';

    public function handle(ChangeRequests $changes): int
    {
        $this->info($changes->remindDue().' reminded.');

        return self::SUCCESS;
    }
}