<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\GroupLifecycleService;

/**
 * Deleted educational / work groups whose time to be brought back ran out are deleted for good,
 * with their terms / projects (docs/groups_tasks.md GR-006).
 */
class PurgeDeletedGroups extends Command
{
    protected $signature = 'chat:purge-deleted-groups';

    protected $description = 'Delete for good the educational / work groups whose restore window ended';

    public function handle(GroupLifecycleService $lifecycle): int
    {
        $this->info($lifecycle->purgeExpired().' group(s) purged.');

        return self::SUCCESS;
    }
}
