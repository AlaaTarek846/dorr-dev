<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\GroupTaskService;

/** Work groups: the next task of every repeating series whose due time came (docs/groups_tasks.md GR-034). */
class SpawnRecurringTasks extends Command
{
    protected $signature = 'chat:spawn-recurring-tasks';

    protected $description = 'Make the next task of each repeating work-group task that is due';

    public function handle(GroupTaskService $tasks): int
    {
        $this->info($tasks->spawnRecurring().' task(s) made.');

        return self::SUCCESS;
    }
}
