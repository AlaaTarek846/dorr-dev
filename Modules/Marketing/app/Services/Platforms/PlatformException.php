<?php

namespace Modules\Marketing\Services\Platforms;

use RuntimeException;

/**
 * What went wrong at an ad platform, sorted by what we must do next: `expired` (reconnect, AT-08), `unreachable`
 * (we don't know if it happened → UNKNOWN + reconcile, AT-06), `rejected` (the platform said no, with its reason).
 */
class PlatformException extends RuntimeException
{
    public const EXPIRED = 'expired';

    public const UNREACHABLE = 'unreachable';

    public const REJECTED = 'rejected';

    public function __construct(public readonly string $kind, string $message = '')
    {
        parent::__construct($message);
    }
}
