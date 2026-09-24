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
 * MyFatoorah — ported from LeeTaxi's app/Traits/MyFatoorahUtil.php +
 * Modules/User/Services/OnlinePayment/MyFatoorahOnlinePaymentService.php,
 * adapted to per-payment_method credentials instead of a global DB row.
 *
 * Credentials expected: `api_url`, `api_key`.
 *
 * The redirect never carries a trusted "success" flag by itself — every
 * completion path re-queries GetPaymentStatus server-to-server with our own
 * API key before trusting anything (matches the source exactly).
 */
class MyFatoorahGateway implements PaymentGateway
{
    public function initiate(GatewayChargeRequest $request): GatewayChargeResult
    {
        $payload = [
            // "LNK": just give us the payment link (we show it ourselves) — no SMS/e-mail from MyFatoorah.
            'NotificationOption' => 'LNK',
            'InvoiceValue' => $this->toMajorUnits($request->amountMinor),
            // MyFatoorah rejects an empty name, and many wallet owners have none yet.
            'CustomerName' => trim($request->customerName) !== '' ? $request->customerName : 'Customer',
            'DisplayCurrencyIso' => $request->currencyCode,
            // Its mobile field is the national number only (max 11 chars), the dial code goes separately.
            'CustomerMobile' => $request->nationalPhone(),
            'MobileCountryCode' => $request->customerDialCode,
            'CallBackUrl' => $request->successUrl,
            'ErrorUrl' => $request->errorUrl,
            'Language' => $request->locale === 'ar' ? 'ar' : 'en',
            'CustomerReference' => $request->localReference,
        ];

        $response = $this->call($request->credentials, '/v2/SendPayment', $payload);
        $json = $response->json();

        if (($json['IsSuccess'] ?? false) !== true) {
            return new GatewayChargeResult(
                success: false,
                rawRequest: $payload,
                rawResponse: $json,
                errorMessage: $this->extractError($json) ?? match ($response->status()) {
                    401, 403 => 'MyFatoorah rejected the API key (HTTP '.$response->status().') — check the credentials in the admin dashboard.',
                    default => 'MyFatoorah SendPayment failed (HTTP '.$response->status().').',
                },
            );
        }

        return new GatewayChargeResult(
            success: true,
            redirectUrl: $json['Data']['InvoiceURL'] ?? null,
            // The invoice id lets an admin reconcile a payment whose browser
            // never reached the callback (no paymentId ever captured).
            gatewayReference: isset($json['Data']['InvoiceId']) ? (string) $json['Data']['InvoiceId'] : null,
            rawRequest: $payload,
            rawResponse: $json,
            extra: ['key_type' => 'InvoiceId'],
        );
    }

    public function handleCallback(Request $httpRequest, array $context = []): GatewayCallbackResult
    {
        $paymentId = $httpRequest->input('paymentId');

        if (! is_string($paymentId) || $paymentId === '') {
            return new GatewayCallbackResult(confirmed: false, errorMessage: 'Missing paymentId on the callback request.');
        }

        return $this->verify($paymentId, $context);
    }

    public function verify(string $gatewayReference, array $context = []): GatewayCallbackResult
    {
        $credentials = $context['credentials'] ?? null;

        if (! is_array($credentials)) {
            throw new PaymentGatewayException('MyFatoorahGateway::verify() needs credentials in $context.');
        }

        $response = $this->call($credentials, '/v2/GetPaymentStatus', [
            'Key' => $gatewayReference,
            // PaymentId right after a redirect; InvoiceId when reconciling.
            'KeyType' => $context['key_type'] ?? 'PaymentId',
        ]);
        $json = $response->json();

        $transactions = $json['Data']['InvoiceTransactions'] ?? [];
        $last = is_array($transactions) && $transactions !== [] ? end($transactions) : null;

        // 'Succss' is MyFatoorah's actual API value (not a typo we introduced)
        // — preserved verbatim from the source being ported.
        $confirmed = is_array($last) && ($last['TransactionStatus'] ?? null) === 'Succss';

        return new GatewayCallbackResult(
            confirmed: $confirmed,
            gatewayReference: $json['Data']['InvoiceId'] ?? $gatewayReference,
            amountMinor: $this->paidAmountMinor($json['Data'] ?? []),
            rawResponse: $json,
            errorMessage: $confirmed ? null : $this->extractError($json),
        );
    }

    public function refund(string $gatewayReference, int $amountMinor, array $context = []): GatewayCallbackResult
    {
        // LeeTaxi's integration never implemented a refund-to-gateway call —
        // refunds there are wallet-side only. Not faking an endpoint here.
        throw new UnsupportedGatewayOperationException('myfatoorah', 'refund');
    }

    public function translateError(?string $code, ?string $fallback, string $locale): string
    {
        return $fallback !== null && $fallback !== '' ? $fallback : __('wallet.errors.gateway_generic', [], $locale);
    }

    private function call(array $credentials, string $endpoint, array $payload): \Illuminate\Http\Client\Response
    {
        $apiUrl = $credentials['api_url'] ?? null;
        $apiKey = $credentials['api_key'] ?? null;

        if (! $apiUrl || ! $apiKey) {
            throw new PaymentGatewayException('MyFatoorah credentials are missing api_url/api_key.');
        }

        try {
            return Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim($apiUrl, '/').$endpoint, $payload);
        } catch (\Throwable $e) {
            throw new PaymentGatewayException('MyFatoorah request failed: '.$e->getMessage(), previous: $e);
        }
    }

    private function extractError(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $errors = $json['ValidationErrors'] ?? $json['FieldsErrors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            return collect($errors)->map(fn ($e) => ($e['Name'] ?? '').': '.($e['Error'] ?? ''))->implode(', ');
        }

        return $json['Data']['ErrorMessage'] ?? $json['Message'] ?? null;
    }

    /**
     * What the customer was asked to pay, in the currency they were asked in. `InvoiceValue` is in the
     * merchant *account's* base currency (a Kuwait test account reports 0.81 for a 10 SAR invoice), so the
     * amount check has to use `InvoiceDisplayValue` ("10.000 SR") when MyFatoorah provides it — otherwise
     * every payment on an account whose base currency differs from the wallet's would look tampered with.
     *
     * @param  array<string, mixed>  $data
     */
    private function paidAmountMinor(array $data): ?int
    {
        $display = $data['InvoiceDisplayValue'] ?? null;

        if (is_string($display)) {
            $ascii = strtr($display, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ',']);

            if (preg_match('/\d[\d,]*(?:\.\d+)?/', $ascii, $m) === 1) {
                return $this->toMinorUnits((float) str_replace(',', '', $m[0]));
            }
        }

        return isset($data['InvoiceValue']) ? $this->toMinorUnits((float) $data['InvoiceValue']) : null;
    }

    private function toMajorUnits(int $amountMinor): float
    {
        return round($amountMinor / 100, 2);
    }

    private function toMinorUnits(float $amountMajor): int
    {
        return (int) round($amountMajor * 100);
    }
}
