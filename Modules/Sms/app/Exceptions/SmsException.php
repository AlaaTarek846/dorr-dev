<?php

namespace Modules\Sms\Exceptions;

use App\Exceptions\ApiRenderable;
use Exception;
use Throwable;

/**
 * Domain failure inside the SMS module. Implementing ApiRenderable lets the
 * service layer throw business errors (provider in use, configuration
 * incomplete, provider inactive) and have ApiExceptionRenderer turn them into
 * the standard API envelope — controllers stay free of try/catch.
 */
class SmsException extends Exception implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        string $message,
        protected int $status = 422,
        protected string $errorCode = 'sms_error',
        protected array $data = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return $this->getMessage();
    }

    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function apiData(): array
    {
        return $this->data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(
        string $message,
        int $status = 422,
        string $errorCode = 'sms_error',
        array $data = [],
    ): self {
        return new self($message, $status, $errorCode, $data);
    }
}
