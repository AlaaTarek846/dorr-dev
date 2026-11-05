<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Offers\TalentPool;

/** Hourly (FS-103): tells a client, once, that the time he set to think of hiring someone from his list again has come. */
class RemindTalentPool extends Command
{
    protected $signature = 'freelance:talent-reminders';

    protected $description = 'Remind clients to hire again someone they asked to be reminded about (FS-103)';

    public function handle(TalentPool $pool): int
    {
        $this->info($pool->remindDue().' reminded.');

        return self::SUCCESS;
    }
}
