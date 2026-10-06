<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * Something the payment screen can tell the customer: not enough balance (with the shortfall, so
 * the app can offer a top-up of just that), already paid, expired…
 */
class CheckoutException extends RuntimeException implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $translationKey,
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

    public static function unknownPurpose(): self
    {
        return new self('checkout_unknown_purpose', 'wallet.errors.checkout_unknown_purpose', 422);
    }

    public static function notFound(): self
    {
        return new self('checkout_not_found', 'wallet.errors.checkout_not_found', 404);
    }

    public static function notPending(): self
    {
        return new self('checkout_not_pending', 'wallet.errors.checkout_not_pending', 409);
    }

    public static function expired(): self
    {
        return new self('checkout_expired', 'wallet.errors.checkout_expired', 410);
    }

    public static function insufficientBalance(int $shortfallMinor): self
    {
        return new self('checkout_insufficient_balance', 'wallet.errors.checkout_insufficient_balance', 422, [], ['shortfall_minor' => $shortfallMinor]);
    }

    public static function gatewayPending(): self
    {
        return new self('checkout_gateway_pending', 'wallet.errors.checkout_gateway_pending', 409);
    }
}
