<?php

namespace Modules\AI\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * A refusal in DORR AI's own tools — memory, context capsules, history search, projects. $reason
 * maps to the "assistant_{reason}" key in the ai language files and the "ai_{reason}" error code.
 */
class AiAssistantException extends RuntimeException implements ApiRenderable
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
        return __('ai.assistant_'.$this->reason);
    }

    public function apiErrorCode(): string
    {
        return 'ai_'.$this->reason;
    }

    public function apiData(): array
    {
        return [];
    }
}
