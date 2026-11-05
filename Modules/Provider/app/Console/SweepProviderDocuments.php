<?php

namespace Modules\Provider\Console;

use Illuminate\Console\Command;
use Modules\Provider\Services\Documents\DocumentService;

/** A-09: marks ended provider papers expired and warns the ones about to end. Runs daily. */
class SweepProviderDocuments extends Command
{
    protected $signature = 'provider:sweep-documents';

    protected $description = 'Expire ended provider documents and warn the ones about to end';

    public function handle(DocumentService $documents): int
    {
        ['expired' => $expired, 'warned' => $warned] = $documents->sweep();
        $this->info("expired: {$expired}, warned: {$warned}");

        return self::SUCCESS;
    }
}
