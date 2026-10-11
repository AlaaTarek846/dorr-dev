<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\LifeService;

/**
 * Commitments (spec 149): an accepted one with a reminder tells the one responsible, once,
 * the chosen time before it's due. Scheduled every minute.
 */
class SendCommitmentReminders extends Command
{
    protected $signature = 'chat:commitment-reminders';

    protected $description = 'Remind people of the commitments they accepted, before they are due';

    public function handle(LifeService $life): int
    {
        $this->info($life->remindDue().' commitment reminder(s) sent.');

        return self::SUCCESS;
    }
}
