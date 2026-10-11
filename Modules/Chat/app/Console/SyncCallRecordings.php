<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\CallRecordingService;

/**
 * Call recordings (spec 92), every minute: requests not everyone answered in time are dropped,
 * files LiveKit has saved become playable, and files past their retention are deleted.
 */
class SyncCallRecordings extends Command
{
    protected $signature = 'chat:call-recordings';

    protected $description = 'Expire unanswered recording requests, pick up saved recordings and delete old ones';

    public function handle(CallRecordingService $recordings): int
    {
        $this->info($recordings->sync().' call recording(s) updated.');

        return self::SUCCESS;
    }
}
