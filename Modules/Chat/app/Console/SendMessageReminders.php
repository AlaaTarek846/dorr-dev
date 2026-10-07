<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\MessageReminderService;

class SendMessageReminders extends Command
{
    protected $signature = 'chat:send-reminders';

    protected $description = 'Send the message reminders whose time has come.';

    public function handle(MessageReminderService $reminders): int
    {
        $this->info($reminders->sendDue().' reminder(s) sent.');

        return self::SUCCESS;
    }
}
