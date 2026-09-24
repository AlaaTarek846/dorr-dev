<?php

namespace Modules\Wallet\Support\Payments;

/**
 * Result of PaymentGateway::handleCallback()/verify()/refund(). `amountMinor`
 * here is informational only — the amount actually credited to a wallet must
 * always come from the caller's own stored requested_amount_minor, never
 * from this (docs/wallet-structure.md §9.3, invariant #5).
 */
final class GatewayCallbackResult
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public readonly bool $confirmed,
        public readonly ?string $gatewayReference = null,
        public readonly ?int $amountMinor = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $errorCode = null,
    ) {}
}
