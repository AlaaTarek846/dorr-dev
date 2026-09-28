<?php

namespace Modules\User\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * A phone-number-change rule the person can act on. Renders itself — see ApiRenderable.
 */
class PhoneChangeException extends RuntimeException implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $replace
     */
    public function __construct(
        public readonly string $errorCode,
        private readonly string $translationKey,
        private readonly int $status = 422,
        private readonly array $replace = [],
    ) {
        parent::__construct($errorCode);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return __($this->translationKey, $this->replace);
    }

    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }

    public function apiData(): array
    {
        return [];
    }

    /** The "new" number is the one already on the account. */
    public static function sameNumber(): self
    {
        return new self('phone_change_same_number', 'api.phone_change_same_number');
    }

    /** Someone else's account already has this number. */
    public static function alreadyTaken(): self
    {
        return new self('phone_change_taken', 'api.phone_change_taken');
    }

    /** No pending change to confirm (never started, or it already completed/expired and was cleared). */
    public static function noneStarted(): self
    {
        return new self('phone_change_none_started', 'api.phone_change_none_started');
    }

    public static function wrongCode(): self
    {
        return new self('phone_change_invalid_code', 'api.phone_change_invalid_code');
    }

    public static function expired(): self
    {
        return new self('phone_change_expired', 'api.phone_change_expired');
    }

    public static function tooManyAttempts(): self
    {
        return new self('phone_change_too_many_attempts', 'api.phone_change_too_many_attempts', 423);
    }
}
