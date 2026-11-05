<?php

namespace Modules\Moving\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Moving\Models\MovingRequest;

/** A request moved (published, an offer came, accepted, assigned…). Notifications and live updates hang off it (B-16). */
class MovingRequestChanged
{
    use Dispatchable;

    public function __construct(public readonly MovingRequest $request, public readonly string $action) {}
}
