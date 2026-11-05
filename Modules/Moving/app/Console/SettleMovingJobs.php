<?php

namespace Modules\Moving\Console;

use Illuminate\Console\Command;
use Modules\Moving\Services\Payment\MovingSettlement;

/** B-11: delivered jobs whose objection window ended pay the establishment (a report keeps only its part held). */
class SettleMovingJobs extends Command
{
    protected $signature = 'moving:settle-due';

    protected $description = 'Pay establishments for delivered moves whose objection window ended';

    public function handle(MovingSettlement $settlement): int
    {
        $this->info('settled: '.$settlement->settleDue());

        return self::SUCCESS;
    }
}
