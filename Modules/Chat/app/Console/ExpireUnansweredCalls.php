<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\CallService;

class ExpireUnansweredCalls extends Command
{
    protected $signature = 'chat:expire-calls';

    protected $description = 'Turn calls nobody answered in time into missed calls.';

    public function handle(CallService $calls): int
    {
        $this->info($calls->expireUnanswered().' call(s) marked missed.');

        return self::SUCCESS;
    }
}
