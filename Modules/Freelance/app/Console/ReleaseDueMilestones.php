<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Projects\MilestoneRelease;

/** Hourly: a delivery the client left unanswered past the review days approves itself and is paid out; a day before, the client is reminded (FL-504). */
class ReleaseDueMilestones extends Command
{
    protected $signature = 'freelance:release-due';

    protected $description = 'Approve and pay out the deliveries left unanswered past their review time (FL-504)';

    public function handle(MilestoneRelease $release): int
    {
        $this->info($release->releaseDue().' part(s) approved by themselves.');

        return self::SUCCESS;
    }
}
