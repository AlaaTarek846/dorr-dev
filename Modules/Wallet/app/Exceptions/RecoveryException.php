<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * A wallet-PIN recovery rule the person can act on. Renders itself — see ApiRenderable.
 */
class RecoveryException extends RuntimeException implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $errorCode,
        private readonly string $translationKey,
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
        return __($this->translationKey, $this->replace);
    }

    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }

    /** A PIN can't be created before a way to get it back has been chosen (and, for e-mail, confirmed). */
    public static function required(): self
    {
        return new self('pin_recovery_required', 'wallet.errors.pin_recovery_required', 422);
    }

    /** Someone who never chose a recovery method (older accounts) trying to recover. */
    public static function notConfigured(): self
    {
        return new self('pin_recovery_not_configured', 'wallet.errors.pin_recovery_not_configured', 422);
    }

    public static function wrongSecret(): self
    {
        return new self('pin_recovery_invalid', 'wallet.errors.pin_recovery_invalid', 422);
    }

    public static function locked(int $minutes): self
    {
        return new self('pin_recovery_locked', 'wallet.errors.pin_recovery_locked', 423, ['minutes' => $minutes]);
    }

    public static function pendingExists(): self
    {
        return new self('pin_recovery_pending_exists', 'wallet.errors.pin_recovery_pending_exists', 409);
    }

    /** The chosen method needs the other kind of input (e.g. a photo, not a password). */
    public static function wrongMethod(string $method): self
    {
        return new self('pin_recovery_wrong_method', 'wallet.errors.pin_recovery_wrong_method', 422, [], ['method' => $method]);
    }

    public static function emailFailed(): self
    {
        return new self('pin_recovery_email_failed', 'wallet.errors.pin_recovery_email_failed', 502);
    }

    public static function notPending(): self
    {
        return new self('pin_recovery_not_pending', 'wallet.errors.pin_recovery_not_pending', 409);
    }

    /** A selfie + ID was submitted, but the wallet isn't actually frozen — nothing to review. */
    public static function notFrozen(): self
    {
        return new self('pin_recovery_not_frozen', 'wallet.errors.pin_recovery_not_frozen', 422);
    }
}
