<?php

namespace Modules\AI\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * Every predictable failure in the subscription purchase flow (plan not
 * found/inactive, currency mismatch, already subscribed, ...) - one small
 * exception class with a translation-key-driven message instead of one
 * class per case, matching how small/numerous these business-rule
 * rejections are (see AiSubscriptionPurchaseService).
 */
class AiSubscriptionException extends RuntimeException implements ApiRenderable
{
    public function __construct(private readonly string $translationKey, private readonly int $status = 422)
    {
        parent::__construct($translationKey);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return __('ai.'.$this->translationKey);
    }

    public function apiErrorCode(): string
    {
        return $this->translationKey;
    }

    public function apiData(): array
    {
        return [];
    }
}
