<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\GroupTaskService;

/** Work groups: an open task due within the hour — its assignee is reminded once (docs/groups_tasks.md GR-039). */
class RemindGroupTasks extends Command
{
    protected $signature = 'chat:remind-group-tasks';

    protected $description = 'Remind assignees of work-group tasks due within the hour';

    public function handle(GroupTaskService $tasks): int
    {
        $this->info($tasks->remindDue().' reminder(s) sent.');

        return self::SUCCESS;
    }
}
