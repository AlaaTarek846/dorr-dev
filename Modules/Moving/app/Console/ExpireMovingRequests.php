<?php

namespace Modules\Moving\Console;

use Illuminate\Console\Command;
use Modules\Moving\Services\Requests\MovingRequestService;

/** REQ-020: requests whose offer time ended without a chosen offer expire; the customer is told and can post again. */
class ExpireMovingRequests extends Command
{
    protected $signature = 'moving:expire-requests';

    protected $description = 'Expire open move requests whose offer time ended';

    public function handle(MovingRequestService $requests): int
    {
        $this->info('expired: '.$requests->expireDue());

        return self::SUCCESS;
    }
}
