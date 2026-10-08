<?php

namespace Modules\AI\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/** A business-rule refusal in the website builder. $reason maps to the "site_{reason}" key in the ai language files. */
class AiSiteException extends RuntimeException implements ApiRenderable
{
    public function __construct(public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($reason);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return __('ai.site_'.$this->reason);
    }

    public function apiErrorCode(): string
    {
        return 'site_'.$this->reason;
    }

    public function apiData(): array
    {
        return [];
    }
}
