<?php

namespace Modules\Provider\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Provider\Models\Establishment;

/** The admin approved, rejected or suspended an establishment; its owner is told. */
class EstablishmentReviewed
{
    use Dispatchable;

    public function __construct(public readonly Establishment $establishment) {}
}
