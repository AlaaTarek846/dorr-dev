<?php

namespace Modules\Provider\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Provider\Models\ProviderDocument;

/** The admin approved or rejected a paper or a vehicle photo; the establishment is told. */
class ProviderDocumentReviewed
{
    use Dispatchable;

    public function __construct(public readonly ProviderDocument $document) {}
}
