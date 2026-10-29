<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\GroupAttendanceService;
use Modules\Chat\Services\GroupClassExtrasService;
use Modules\Chat\Services\GroupParentService;
use Modules\Chat\Services\GroupWorkExtrasService;

/** Educational groups: a class session within 30 minutes (GR-017) or a test opening within the hour (GR-022) — its students are reminded once. */
class RemindGroupSessions extends Command
{
    protected $signature = 'chat:remind-group-sessions';

    protected $description = 'Remind students of class sessions starting within 30 minutes and tests opening within the hour';

    public function handle(GroupAttendanceService $attendance, GroupClassExtrasService $extras, GroupWorkExtrasService $work, GroupParentService $parents): int
    {
        $this->info($attendance->remindSoon().' session(s) reminded.');
        // GR-022: a test opening within the hour.
        $this->info($extras->remindTests().' test(s) reminded.');
        // GR-046: a work meeting within 30 minutes.
        $this->info($work->remindMeetings().' meeting(s) reminded.');
        // GR-027: a parents' meeting, or a parent's slot, within 30 minutes.
        $this->info($parents->remindMeetings().' parent meeting reminder(s).');

        return self::SUCCESS;
    }
}
