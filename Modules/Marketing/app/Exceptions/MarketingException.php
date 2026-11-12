<?php

namespace Modules\Marketing\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/** A marketing rule the person can act on. Renders itself as `marketing_<code>` (see ApiRenderable). */
class MarketingException extends RuntimeException implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $errorCode,
        private readonly int $status = 422,
        private readonly array $replace = [],
        private readonly array $data = [],
    ) {
        parent::__construct($errorCode);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return __('marketing.errors.'.$this->errorCode, $this->replace);
    }

    public function apiErrorCode(): string
    {
        return 'marketing_'.$this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }
}
