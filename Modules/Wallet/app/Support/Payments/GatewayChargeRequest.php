<?php

namespace Modules\Wallet\Support\Payments;

/**
 * What a Modules\Wallet\Contracts\PaymentGateway needs to start a charge —
 * deliberately has no idea payment_transactions exists (that table is
 * Phase 6, this is Phase 5): the caller persists whatever it needs from the
 * result, the gateway driver is a stateless adapter to the external API.
 */
final class GatewayChargeRequest
{
    /**
     * @param  array<string, mixed>  $credentials  payment_methods.credentials, decrypted
     */
    public function __construct(
        public readonly array $credentials,
        public readonly int $amountMinor,
        public readonly string $currencyCode,
        public readonly string $customerName,
        public readonly ?string $customerPhone,
        public readonly ?string $customerEmail,
        public readonly string $localReference,
        public readonly string $successUrl,
        public readonly string $errorUrl,
        public readonly string $locale = 'en',
        /** The country's dial code ("+966") — for gateways that want the mobile number without it. */
        public readonly ?string $customerDialCode = null,
    ) {}

    /**
     * The customer's mobile without the country dial code ("+966500000123" → "500000123"), or null.
     */
    public function nationalPhone(): ?string
    {
        if ($this->customerPhone === null || $this->customerPhone === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '',$this->customerPhone);
        $dial = ltrim((string) $this->customerDialCode, '+');

        if ($dial !== '' && str_starts_with($this->customerPhone, '+') && str_starts_with($digits, $dial)) {
            $digits = substr($digits, strlen($dial));
        }

        return $digits === '' ? null : $digits;
    }
}
