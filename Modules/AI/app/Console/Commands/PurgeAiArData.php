<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Services\Ar\AiArCaptureService;

/** Applies the AR capture retention policy: expired photos/videos are deleted from disk and database. */
class PurgeAiArData extends Command
{
    protected $signature = 'ai:purge-ar';

    protected $description = 'Delete expired DORR AR captures';

    public function handle(AiArCaptureService $captures): int
    {
        $this->info('captures='.$captures->purgeExpired());

        return self::SUCCESS;
    }
}
