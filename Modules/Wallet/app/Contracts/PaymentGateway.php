<?php

namespace Modules\Wallet\Contracts;

use Illuminate\Http\Request;
use Modules\Wallet\Support\Payments\GatewayCallbackResult;
use Modules\Wallet\Support\Payments\GatewayChargeRequest;
use Modules\Wallet\Support\Payments\GatewayChargeResult;

/**
 * One driver per payment_methods.gateway value (Modules\Wallet\Services\PaymentGatewayRegistry
 * picks the concrete class). Every implementation MUST verify with the
 * gateway server-to-server before trusting a "success" — never trust a bare
 * redirect querystring/body alone (docs/wallet-structure.md §9.3, invariant #1).
 *
 * `$context` on the last three methods carries whatever `extra` the matching
 * initiate() call returned, when the gateway's flow needs it (e.g. URPay's
 * OTP-execute step needs the security token from initiate) — callers get
 * this from their own persisted state, not from the gateway driver, which
 * holds nothing between calls.
 */
interface PaymentGateway
{
    public function initiate(GatewayChargeRequest $request): GatewayChargeResult;

    /**
     * @param  array<string, mixed>  $context
     */
    public function handleCallback(Request $httpRequest, array $context = []): GatewayCallbackResult;

    /**
     * Server-to-server status re-check — used both right after a callback
     * (defense in depth) and for manual "Reconcile" on a stuck `pending`
     * payment_transactions row (docs/wallet-structure.md §9.3, invariant #2).
     *
     * @param  array<string, mixed>  $context
     */
    public function verify(string $gatewayReference, array $context = []): GatewayCallbackResult;

    /**
     * @param  array<string, mixed>  $context
     */
    public function refund(string $gatewayReference, int $amountMinor, array $context = []): GatewayCallbackResult;

    /**
     * A message safe to show the customer for a gateway error code — never
     * the raw gateway text unless nothing better exists. Locale is dynamic
     * (dorr has admin-managed languages), so implementations must always end
     * in a generic translated message rather than assuming en/ar only.
     */
    public function translateError(?string $code, ?string $fallback, string $locale): string;
}
