<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\ScheduledMessageService;

class SendScheduledMessages extends Command
{
    protected $signature = 'chat:send-scheduled';

    protected $description = 'Send the scheduled chat messages whose time has come.';

    public function handle(ScheduledMessageService $scheduled): int
    {
        $this->info($scheduled->sendDue().' scheduled message(s) sent.');

        return self::SUCCESS;
    }
}
