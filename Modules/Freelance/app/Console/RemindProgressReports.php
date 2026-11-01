<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Projects\ProgressReports;

/** Hourly (FL-605): reminds a freelancer of a progress report that is due, and tells the client when it is a day late - once each. */
class RemindProgressReports extends Command
{
    protected $signature = 'freelance:report-reminders';

    protected $description = 'Remind freelancers of due progress reports and tell clients about late ones (FL-605)';

    public function handle(ProgressReports $reports): int
    {
        $this->info($reports->remindDue().' notified.');

        return self::SUCCESS;
    }
}
