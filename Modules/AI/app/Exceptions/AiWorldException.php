<?php

namespace Modules\AI\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/** A business-rule refusal in DORR AI WORLD. $reason maps to the "world_{reason}" key in the ai language files. */
class AiWorldException extends RuntimeException implements ApiRenderable
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
        return __('ai.world_'.$this->reason);
    }

    public function apiErrorCode(): string
    {
        return 'world_'.$this->reason;
    }

    public function apiData(): array
    {
        return [];
    }
}
