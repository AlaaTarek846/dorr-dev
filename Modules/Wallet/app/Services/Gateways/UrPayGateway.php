<?php

namespace Modules\Wallet\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Wallet\Contracts\PaymentGateway;
use Modules\Wallet\Exceptions\PaymentGatewayException;
use Modules\Wallet\Exceptions\UnsupportedGatewayOperationException;
use Modules\Wallet\Support\Payments\GatewayCallbackResult;
use Modules\Wallet\Support\Payments\GatewayChargeRequest;
use Modules\Wallet\Support\Payments\GatewayChargeResult;

/**
 * URPay (mobile-wallet-to-wallet) — ported from Jawad's
 * app/Services/URPaymentService.php. Not a redirect gateway: initiate()
 * sends an OTP to the customer's phone, and the caller must collect that OTP
 * and pass it to handleCallback() to complete the charge — GatewayChargeResult
 * here never has a redirectUrl.
 *
 * Credentials expected: `mode` (test/production), `payment_url`, `username`,
 * `password`, `client_id`, `terminal_id`, `merchant_wallet_number`,
 * `merchant_id`, and (test mode only) `test_consumer_mobile_number`.
 *
 * `verify()` is intentionally unsupported: Jawad's integration never exposed
 * a standalone status-check endpoint, only this OTP-execute step confirms a
 * payment — see UnsupportedGatewayOperationException.
 */
class UrPayGateway implements PaymentGateway
{
    public function initiate(GatewayChargeRequest $request): GatewayChargeResult
    {
        $credentials = $request->credentials;
        $this->assertCredentials($credentials);

        $headers = [
            'X-Client-Id' => $credentials['client_id'],
            'X-Session-Language' => $request->locale === 'ar' ? 'AR' : 'EN',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        try {
            $tokenResponse = Http::withHeaders($headers)->timeout(15)->post(
                rtrim($credentials['payment_url'], '/').'/v1/payments/merchant/generatetoken',
                ['userName' => $credentials['username'], 'password' => $credentials['password']],
            );
        } catch (\Throwable $e) {
            throw new PaymentGatewayException('URPay generatetoken request failed: '.$e->getMessage(), previous: $e);
        }

        $securityToken = $tokenResponse->header('X-Security-Token');

        if (! $securityToken) {
            return new GatewayChargeResult(success: false, rawResponse: $tokenResponse->json(), errorMessage: 'Failed to get URPay security token.');
        }

        $mobileNumber = ($credentials['mode'] ?? 'test') === 'production'
            ? $request->customerPhone
            : ($credentials['test_consumer_mobile_number'] ?? $request->customerPhone);

        $transactionInfo = [
            'amount' => ['currency' => $request->currencyCode, 'value' => $this->toMajorUnits($request->amountMinor)],
            'externalTransactionId' => $request->localReference,
            'sourceConsumerMobileNumber' => $mobileNumber,
            'targetMerchantId' => $credentials['merchant_id'],
            'targetMerchantWalletNumber' => $credentials['merchant_wallet_number'],
            'targetTerminalId' => $credentials['terminal_id'],
        ];

        $initiateHeaders = $headers + [
            'X-Security-Token' => $securityToken,
            'X-Request-Id' => $tokenResponse->header('X-Request-Id'),
            'X-Session-Id' => $tokenResponse->header('X-Session-Id'),
        ];

        try {
            $response = Http::withHeaders($initiateHeaders)->timeout(15)->post(
                rtrim($credentials['payment_url'], '/').'/v1/payments/ecomm/initiate',
                ['transactionInfo' => $transactionInfo],
            );
        } catch (\Throwable $e) {
            throw new PaymentGatewayException('URPay initiate request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();

        if (($json['header']['status']['code'] ?? null) !== 'I000000') {
            return new GatewayChargeResult(
                success: false,
                rawRequest: ['transactionInfo' => $transactionInfo],
                rawResponse: $json,
                errorMessage: $json['header']['status']['description'] ?? 'Failed to initiate URPay payment.',
            );
        }

        $executeContext = [
            'header' => $initiateHeaders + ['X-Verification-Token' => $response->header('X-Verification-Token')],
            'body' => [
                'transactionInfo' => $transactionInfo,
                'OTPInfo' => ['otp' => '', 'otpReference' => $json['body']['otpReference'] ?? null],
            ],
        ];

        return new GatewayChargeResult(
            success: true,
            redirectUrl: null, // OTP flow, not a redirect — the caller prompts for the OTP directly
            gatewayReference: $tokenResponse->header('X-Request-Id'),
            rawRequest: ['transactionInfo' => $transactionInfo],
            rawResponse: $json,
            extra: $executeContext,
        );
    }

    public function handleCallback(Request $httpRequest, array $context = []): GatewayCallbackResult
    {
        $otp = $httpRequest->input('otp');

        if (! is_string($otp) || $otp === '') {
            return new GatewayCallbackResult(confirmed: false, errorMessage: 'Missing otp on the callback request.');
        }

        $header = $context['header'] ?? null;
        $body = $context['body'] ?? null;

        if (! is_array($header) || ! is_array($body)) {
            throw new PaymentGatewayException("UrPayGateway::handleCallback() needs the 'header'/'body' saved from initiate() in \$context.");
        }

        $body['OTPInfo']['otp'] = $otp;
        $paymentUrl = $context['payment_url'] ?? null;

        if (! $paymentUrl) {
            throw new PaymentGatewayException("UrPayGateway::handleCallback() needs 'payment_url' in \$context.");
        }

        try {
            $response = Http::withHeaders($header)->timeout(20)->post(rtrim($paymentUrl, '/').'/v1/payments/ecomm/execute', $body);
        } catch (\Throwable $e) {
            throw new PaymentGatewayException('URPay execute request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $transactionReference = $json['body']['transactionReferenceId'] ?? null;

        return new GatewayCallbackResult(
            confirmed: $transactionReference !== null,
            gatewayReference: $transactionReference,
            rawResponse: $json,
            errorMessage: $transactionReference === null ? ($json['header']['status']['description'] ?? 'URPay payment not confirmed.') : null,
            errorCode: $json['header']['status']['code'] ?? null,
        );
    }

    public function verify(string $gatewayReference, array $context = []): GatewayCallbackResult
    {
        throw new UnsupportedGatewayOperationException('urpay', 'verify (no standalone status-check endpoint in the source integration)');
    }

    public function refund(string $gatewayReference, int $amountMinor, array $context = []): GatewayCallbackResult
    {
        throw new UnsupportedGatewayOperationException('urpay', 'refund');
    }

    public function translateError(?string $code, ?string $fallback, string $locale): string
    {
        // Same two codes Jawad special-cased: an invalid/expired OTP.
        if (in_array($code, ['E430019', 'E430054'], true)) {
            return __('wallet.errors.otp_invalid', [], $locale);
        }

        return $fallback !== null && $fallback !== '' ? $fallback : __('wallet.errors.gateway_generic', [], $locale);
    }

    private function assertCredentials(array $credentials): void
    {
        foreach (['payment_url', 'username', 'password', 'client_id', 'terminal_id', 'merchant_wallet_number', 'merchant_id'] as $key) {
            if (empty($credentials[$key])) {
                throw new PaymentGatewayException("URPay credentials are missing '{$key}'.");
            }
        }
    }

    private function toMajorUnits(int $amountMinor): float
    {
        return round($amountMinor / 100, 2);
    }
}
