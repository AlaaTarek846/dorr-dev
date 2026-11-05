<?php

namespace Modules\Moving\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/** A moving rule the caller can act on. Renders itself as `moving_<code>` (see ApiRenderable). */
class MovingException extends RuntimeException implements ApiRenderable
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
        return __('moving.errors.'.$this->errorCode, $this->replace);
    }

    public function apiErrorCode(): string
    {
        return 'moving_'.$this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }
}
