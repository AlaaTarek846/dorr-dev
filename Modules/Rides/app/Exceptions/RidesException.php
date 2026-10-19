<?php

namespace Modules\Rides\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/** A rides rule the person can act on. Renders itself as `rides_<code>` (see ApiRenderable). */
class RidesException extends RuntimeException implements ApiRenderable
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
        // A fuller wording of the same error, when the caller knows more (the code the apps check stays the same).
        private readonly ?string $messageKey = null,
    ) {
        parent::__construct($errorCode);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return __('rides.errors.'.($this->messageKey ?? $this->errorCode), $this->replace);
    }

    public function apiErrorCode(): string
    {
        return 'rides_'.$this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }

    /** The service, a category or a feature is switched off where this person is (§5 of the plan). */
    public static function featureDisabled(string $feature): self
    {
        return new self('feature_disabled', 403, [], ['feature' => $feature]);
    }

    /**
     * @param  list<string>  $keys
     */
    public static function invalidPolicy(array $keys): self
    {
        return new self('invalid_policy', 422, ['keys' => implode(', ', $keys)], ['keys' => $keys]);
    }
}
