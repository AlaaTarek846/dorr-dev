<?php

namespace Modules\AI\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/** A business-rule refusal in DORR AR. $reason maps to the "ar_{reason}" key in the ai language files. */
class AiArException extends RuntimeException implements ApiRenderable
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
        return __('ai.ar_'.$this->reason);
    }

    public function apiErrorCode(): string
    {
        return 'ar_'.$this->reason;
    }

    public function apiData(): array
    {
        return [];
    }
}
