<?php

namespace Modules\Wallet\Support\Payments;

/**
 * Result of PaymentGateway::initiate(). `extra` is whatever gateway-specific
 * state the caller must persist and hand back on the next call for this
 * transaction (e.g. URPay's security token/headers between initiate and the
 * OTP-execute callback) — the gateway driver itself keeps no state.
 */
final class GatewayChargeResult
{
    /**
     * @param  array<string, mixed>|null  $rawRequest
     * @param  array<string, mixed>|null  $rawResponse
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $gatewayReference = null,
        public readonly ?array $rawRequest = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $errorMessage = null,
        public readonly array $extra = [],
    ) {}
}
