<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * A top-up/payment rule violation the customer or admin can act on (wrong
 * amount, unavailable method, payment not pending...). Carries its own HTTP
 * status, translation key and machine-readable code — see ApiRenderable.
 */
class TopupException extends RuntimeException implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $replace  translation placeholders
     * @param  array<string, mixed>  $data  extra machine-readable context
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

    public static function methodUnavailable(): self
    {
        return new self('payment_method_unavailable', 'wallet.errors.method_unavailable', 422);
    }

    /**
     * Listed, but the gateway has no credentials yet.
     */
    public static function comingSoon(): self
    {
        return new self('payment_method_coming_soon', 'wallet.errors.method_coming_soon', 422);
    }

    public static function amountOutOfRange(?int $minMinor, ?int $maxMinor): self
    {
        return new self(
            'amount_out_of_range',
            'wallet.errors.amount_out_of_range',
            422,
            ['min' => $minMinor === null ? '-' : $minMinor / 100, 'max' => $maxMinor === null ? '-' : $maxMinor / 100],
            ['min_minor' => $minMinor, 'max_minor' => $maxMinor],
        );
    }

    /**
     * The gateway declined/refused — `$message` is already customer-safe
     * (PaymentGateway::translateError), never raw gateway text.
     */
    public static function gatewayRejected(string $message): self
    {
        return new self('gateway_rejected', 'wallet.errors.gateway_rejected', 422, ['message' => $message]);
    }

    public static function paymentNotFound(): self
    {
        return new self('payment_not_found', 'wallet.errors.payment_not_found', 404);
    }

    public static function paymentNotPending(): self
    {
        return new self('payment_not_pending', 'wallet.errors.payment_not_pending', 409);
    }

    public static function reconcileUnsupported(): self
    {
        return new self('reconcile_unsupported', 'wallet.errors.reconcile_unsupported', 422);
    }

    public static function refundNotPaid(): self
    {
        return new self('refund_not_paid', 'wallet.errors.refund_not_paid', 409);
    }

    public static function refundNotWhole(): self
    {
        return new self('refund_not_whole', 'wallet.errors.refund_not_whole', 409);
    }
}
