<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Consultations\ConsultationSessions;

/**
 * Every minute (FL-L16): warn both sides before a session ends, close rooms whose time is over, follow the recordings LiveKit saves, move
 * them from the bucket to our disk, and delete them a week after the end (or after the staff's decision on a complaint).
 */
class SyncConsultationSessions extends Command
{
    protected $signature = 'freelance:sync-consultation-sessions';

    protected $description = 'Close consultation video rooms on time and keep their recordings for a week (FL-L16)';

    public function handle(ConsultationSessions $sessions): int
    {
        $done = $sessions->sync();
        $this->info("{$done['warned']} warned, {$done['closed']} closed, {$done['started']} recordings started, {$done['saved']} saved, {$done['moved']} moved home, {$done['deleted']} deleted.");

        return self::SUCCESS;
    }
}
