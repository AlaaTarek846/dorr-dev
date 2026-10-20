<?php

namespace Modules\Driver\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/** A driver rule the driver can act on. Renders itself as `driver_<code>` (see ApiRenderable). */
class DriverException extends RuntimeException implements ApiRenderable
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
        return __('driver.errors.'.$this->errorCode, $this->replace);
    }

    public function apiErrorCode(): string
    {
        return 'driver_'.$this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }
}
