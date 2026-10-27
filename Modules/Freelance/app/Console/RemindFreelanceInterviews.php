<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Chat\FreelanceInterviews;

/** Every minute (FL-310): tells both sides of a confirmed interview that it starts soon - once. */
class RemindFreelanceInterviews extends Command
{
    protected $signature = 'freelance:interview-reminders';

    protected $description = 'Remind the client and the freelancer of interviews that are about to start (FL-310)';

    public function handle(FreelanceInterviews $interviews): int
    {
        $this->info($interviews->remindDue().' reminded.');

        return self::SUCCESS;
    }
}
