<?php

namespace Modules\Wallet\Services;

use Modules\Wallet\Enums\PaymentGatewayLogEvent;
use Modules\Wallet\Models\PaymentGatewayLog;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Support\Payments\LogSanitizer;

/**
 * The only writer of payment_gateway_logs (append-only, docs/wallet-structure.md
 * §9.2). Everything goes through LogSanitizer so a credential can never end up
 * in a log row, whichever caller forgot to strip it.
 */
class PaymentLogger
{
    /**
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $response
     * @param  array{type: string, id: int|null}|null  $triggeredBy
     */
    public function record(
        ?PaymentTransaction $payment,
        string $providerCode,
        PaymentGatewayLogEvent $event,
        string $direction,
        ?array $request = null,
        ?array $response = null,
        ?int $httpStatus = null,
        ?string $gatewayStatus = null,
        ?string $externalReference = null,
        ?array $triggeredBy = null,
    ): PaymentGatewayLog {
        return PaymentGatewayLog::query()->create([
            'payment_transaction_id' => $payment?->id,
            'provider_code' => $providerCode,
            'event' => $event,
            'direction' => $direction,
            'http_status' => $httpStatus,
            'request_payload' => LogSanitizer::redact($request),
            'response_payload' => LogSanitizer::redact($response),
            'gateway_status_reported' => $gatewayStatus,
            'external_reference' => $externalReference,
            'triggered_by_type' => $triggeredBy['type'] ?? null,
            'triggered_by_id' => $triggeredBy['id'] ?? null,
        ]);
    }
}
