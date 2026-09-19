<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class ConflictException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        int $code = 409,
        ?Throwable $previous = null,
        public readonly ?string $reason = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message !== '' ? $message : __('api.conflict'), $code, $previous);
    }
}
