<?php

namespace Modules\Wallet\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Wallet\Contracts\PaymentGateway;
use Modules\Wallet\Exceptions\UnsupportedGatewayOperationException;
use Modules\Wallet\Support\Payments\GatewayCallbackResult;
use Modules\Wallet\Support\Payments\GatewayChargeRequest;
use Modules\Wallet\Support\Payments\GatewayChargeResult;

/**
 * A fake "bank" for development and demos — NOT a real gateway, and only
 * registered when `wallet.sandbox_enabled` is on (default: local/testing only).
 * It exists so the whole top-up flow (redirect → decision → callback → verify →
 * credit) can be exercised without real gateway credentials.
 *
 * It deliberately behaves like the real ones where it matters: the customer's
 * redirect carries only a reference, and completion is decided by asking this
 * "bank" server-side ({@see self::verify()}) — a forged `?status=success`
 * moves no money here either. The bank's state lives in the cache under the
 * reference; only the sandbox checkout page (SandboxCheckoutController) can
 * set it to paid.
 */
class SandboxGateway implements PaymentGateway
{
    private const TTL_MINUTES = 60;

    public static function stateKey(string $reference): string
    {
        return "wallet:sandbox:{$reference}";
    }

    public function initiate(GatewayChargeRequest $request): GatewayChargeResult
    {
        $reference = 'SBX-'.Str::upper(Str::random(10));

        Cache::put(self::stateKey($reference), [
            'status' => 'pending',
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currencyCode,
            'local_reference' => $request->localReference,
            'return_url' => $request->successUrl,
        ], now()->addMinutes(self::TTL_MINUTES));

        return new GatewayChargeResult(
            success: true,
            redirectUrl: route('api.wallet.sandbox.checkout', ['reference' => $reference]),
            gatewayReference: $reference,
            rawRequest: ['amount_minor' => $request->amountMinor, 'currency' => $request->currencyCode],
            rawResponse: ['reference' => $reference, 'status' => 'pending'],
        );
    }

    public function handleCallback(Request $httpRequest, array $context = []): GatewayCallbackResult
    {
        $reference = $httpRequest->input('reference');

        if (! is_string($reference) || $reference === '') {
            return new GatewayCallbackResult(confirmed: false, errorMessage: 'Missing reference on the callback request.');
        }

        return $this->verify($reference, $context);
    }

    public function verify(string $gatewayReference, array $context = []): GatewayCallbackResult
    {
        $state = Cache::get(self::stateKey($gatewayReference));

        if (! is_array($state)) {
            return new GatewayCallbackResult(confirmed: false, gatewayReference: $gatewayReference, errorMessage: 'Unknown or expired sandbox payment.');
        }

        $paid = ($state['status'] ?? null) === 'paid';

        return new GatewayCallbackResult(
            confirmed: $paid,
            gatewayReference: $gatewayReference,
            amountMinor: $state['amount_minor'] ?? null,
            rawResponse: ['status' => $state['status'] ?? null, 'amount_minor' => $state['amount_minor'] ?? null],
            errorMessage: $paid ? null : 'The sandbox payment was not approved.',
        );
    }

    public function refund(string $gatewayReference, int $amountMinor, array $context = []): GatewayCallbackResult
    {
        throw new UnsupportedGatewayOperationException('sandbox', 'refund');
    }

    public function translateError(?string $code, ?string $fallback, string $locale): string
    {
        return __('wallet.errors.gateway_generic', [], $locale);
    }
}
