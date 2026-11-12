<?php

namespace Modules\Provider\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Provider\Models\Membership;

/** Someone left or was removed. Services that assigned them upcoming work hand it back to the establishment (§14). */
class MembershipEnded
{
    use Dispatchable;

    public function __construct(public readonly Membership $membership) {}
}
