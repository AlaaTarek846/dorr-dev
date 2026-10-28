<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Services\World\AiWorldPrivacyService;

/** Applies the DORR AI WORLD retention policy: old trip videos and expired reference photos are deleted. */
class PurgeAiWorldData extends Command
{
    protected $signature = 'ai:purge-world';

    protected $description = 'Delete expired DORR AI WORLD videos and reference photos';

    public function handle(AiWorldPrivacyService $privacy): int
    {
        $result = $privacy->purgeExpired();
        $this->info('projects='.$result['projects'].' profiles='.$result['profiles']);

        return self::SUCCESS;
    }
}
