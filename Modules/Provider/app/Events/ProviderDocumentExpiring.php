<?php

namespace Modules\Provider\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Provider\Models\ProviderDocument;

/** A paper ends within the days the admin set on its type; the establishment is warned once. */
class ProviderDocumentExpiring
{
    use Dispatchable;

    public function __construct(public readonly ProviderDocument $document) {}
}
