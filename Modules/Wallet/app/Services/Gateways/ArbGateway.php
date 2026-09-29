<?php

namespace Modules\Wallet\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Wallet\Contracts\PaymentGateway;
use Modules\Wallet\Exceptions\PaymentGatewayException;
use Modules\Wallet\Support\Payments\GatewayCallbackResult;
use Modules\Wallet\Support\Payments\GatewayChargeRequest;
use Modules\Wallet\Support\Payments\GatewayChargeResult;

/**
 * Al Rajhi Bank (ARB) Tranportal — ported from Jawad's
 * app/Services/ARBPaymentService.php, adapted to per-payment_method
 * credentials. The AES-256-CBC envelope (encrypt/decrypt + PKCS5 padding +
 * hex<->byte helpers below) is copied verbatim — it's ARB's own protocol,
 * not something to "modernize".
 *
 * Credentials expected: `tranportal_id`, `tranportal_password`,
 * `tranportal_resource_key`, `hosted_url`.
 */
class ArbGateway implements PaymentGateway
{
    private const IV = 'PGKEYENCDECIVSPC';

    private const CURRENCY_CODE_SAR = '682';

    public function initiate(GatewayChargeRequest $request): GatewayChargeResult
    {
        $credentials = $request->credentials;
        $this->assertCredentials($credentials);

        $trackId = $request->localReference;

        $tranData = json_encode([[
            'amt' => (string) $this->toMajorUnits($request->amountMinor),
            'action' => '1',
            'password' => $credentials['tranportal_password'],
            'id' => $credentials['tranportal_id'],
            'currencyCode' => self::CURRENCY_CODE_SAR,
            'responseURL' => $request->successUrl,
            'errorURL' => $request->errorUrl,
            'langid' => $request->locale === 'ar' ? 'ar' : 'en',
            'trackId' => $trackId,
        ]], JSON_THROW_ON_ERROR);

        $payload = [[
            'id' => $credentials['tranportal_id'],
            'trandata' => $this->encryptAes($tranData, $credentials['tranportal_resource_key']),
            'responseURL' => $request->successUrl,
            'errorURL' => $request->errorUrl,
        ]];

        try {
            $response = Http::acceptJson()->timeout(20)->post($credentials['hosted_url'], $payload);
        } catch (\Throwable $e) {
            throw new PaymentGatewayException('ARB request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $result = $json[0]['result'] ?? null;

        if (! is_string($result) || ! str_contains($result, ':')) {
            return new GatewayChargeResult(
                success: false,
                rawRequest: $payload,
                rawResponse: $json,
                errorMessage: $json[0]['error'] ?? 'ARB hosted payment request failed.',
            );
        }

        // Format: "PAYMENTID:https_host_and_path_fragment:..." (source's own convention).
        $parts = explode(':', $result);
        $paymentId = $parts[0];
        $redirectUrl = 'https:'.$parts[2].'?PaymentID='.$paymentId;

        return new GatewayChargeResult(
            success: true,
            redirectUrl: $redirectUrl,
            gatewayReference: $paymentId,
            rawRequest: $payload,
            rawResponse: $json,
            extra: ['track_id' => $trackId, 'response_url' => $request->successUrl, 'error_url' => $request->errorUrl],
        );
    }

    public function handleCallback(Request $httpRequest, array $context = []): GatewayCallbackResult
    {
        $trandata = $httpRequest->input('trandata');
        $paymentId = $httpRequest->input('paymentid');
        $credentials = $context['credentials'] ?? null;

        if (! is_string($trandata) || ! is_string($paymentId)) {
            return new GatewayCallbackResult(confirmed: false, errorMessage: 'Missing paymentid/trandata on the callback request.');
        }

        if (! is_array($credentials)) {
            throw new PaymentGatewayException('ArbGateway::handleCallback() needs credentials in $context.');
        }

        $decrypted = $this->decryptAes($trandata, $credentials['tranportal_resource_key']);

        return new GatewayCallbackResult(
            confirmed: ($decrypted['result'] ?? null) === 'CAPTURED',
            gatewayReference: $decrypted['paymentId'] ?? $paymentId,
            rawResponse: $decrypted,
            errorMessage: ($decrypted['result'] ?? null) !== 'CAPTURED' ? ($decrypted['error'] ?? 'Payment not captured.') : null,
            errorCode: $decrypted['error'] ?? null,
        );
    }

    public function verify(string $gatewayReference, array $context = []): GatewayCallbackResult
    {
        $credentials = $context['credentials'] ?? null;

        if (! is_array($credentials)) {
            throw new PaymentGatewayException('ArbGateway::verify() needs credentials in $context.');
        }

        $amountMinor = $context['amount_minor'] ?? null;
        $trackId = $context['track_id'] ?? null;

        if ($amountMinor === null) {
            throw new PaymentGatewayException('ArbGateway::verify() needs amount_minor in $context (ARB\'s inquiry re-sends the original amount).');
        }

        $tranportalUrl = str_replace('hosted.htm', 'tranportal.htm', (string) $credentials['hosted_url']);

        $tranData = json_encode([[
            'amt' => (string) $this->toMajorUnits($amountMinor),
            'action' => '8',
            'password' => $credentials['tranportal_password'],
            'id' => $credentials['tranportal_id'],
            'currencyCode' => self::CURRENCY_CODE_SAR,
            'trackId' => $trackId,
            'udf5' => 'PaymentID',
            'transId' => $gatewayReference,
        ]], JSON_THROW_ON_ERROR);

        $payload = [[
            'id' => $credentials['tranportal_id'],
            'trandata' => $this->encryptAes($tranData, $credentials['tranportal_resource_key']),
            'responseURL' => $context['response_url'] ?? null,
            'errorURL' => $context['error_url'] ?? null,
        ]];

        try {
            $response = Http::acceptJson()->timeout(20)->post($tranportalUrl, $payload);
        } catch (\Throwable $e) {
            throw new PaymentGatewayException('ARB inquiry request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();

        if (! isset($json[0]['trandata'])) {
            return new GatewayCallbackResult(
                confirmed: false,
                gatewayReference: $gatewayReference,
                rawResponse: $json,
                errorMessage: $json[0]['error'] ?? 'ARB inquiry returned no data.',
            );
        }

        $decrypted = $this->decryptAes($json[0]['trandata'], $credentials['tranportal_resource_key']);
        $resultCode = strtoupper((string) ($decrypted['result'] ?? $decrypted['Result'] ?? ''));

        return new GatewayCallbackResult(
            confirmed: in_array($resultCode, ['CAPTURED', 'APPROVED'], true),
            gatewayReference: $gatewayReference,
            rawResponse: $decrypted,
            errorMessage: in_array($resultCode, ['CAPTURED', 'APPROVED'], true) ? null : ($decrypted['errorText'] ?? $resultCode),
        );
    }

    public function refund(string $gatewayReference, int $amountMinor, array $context = []): GatewayCallbackResult
    {
        // Not present in Jawad's ARBPaymentService — not faking it here.
        throw new \Modules\Wallet\Exceptions\UnsupportedGatewayOperationException('arb', 'refund');
    }

    public function translateError(?string $code, ?string $fallback, string $locale): string
    {
        static $table = null;
        $table ??= require dirname(__DIR__, 3).'/resources/gateway-errors/arb.php';

        if ($code !== null && isset($table[trim($code)])) {
            $row = $table[trim($code)];
            $lang = strtolower(substr($locale, 0, 2));

            return $row[$lang] ?? $row['en'];
        }

        return __('wallet.errors.gateway_generic', [], $locale);
    }

    private function assertCredentials(array $credentials): void
    {
        foreach (['tranportal_id', 'tranportal_password', 'tranportal_resource_key', 'hosted_url'] as $key) {
            if (empty($credentials[$key])) {
                throw new PaymentGatewayException("ARB credentials are missing '{$key}'.");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptAes(string $code, string $resourceKey): array
    {
        $bytes = $this->hexToByteArray(trim($code));
        $binary = $this->byteArrayToString($bytes);
        $base64 = base64_encode($binary);

        $decrypted = openssl_decrypt($base64, 'AES-256-CBC', $resourceKey, OPENSSL_ZERO_PADDING, self::IV);

        if ($decrypted === false) {
            throw new PaymentGatewayException('ARB trandata decryption failed.');
        }

        $json = urldecode($this->pkcs5Unpad($decrypted));
        $decoded = json_decode($json, true);

        return is_array($decoded[0] ?? null) ? $decoded[0] : (array) ($decoded[0] ?? []);
    }

    private function encryptAes(string $json, string $resourceKey): string
    {
        $padded = $this->pkcs5Pad($json);
        $encrypted = openssl_encrypt($padded, 'aes-256-cbc', $resourceKey, OPENSSL_ZERO_PADDING, self::IV);

        if ($encrypted === false) {
            throw new PaymentGatewayException('ARB trandata encryption failed.');
        }

        $binary = base64_decode($encrypted);
        $bytes = unpack('C*', $binary);

        return urlencode($this->byteArrayToHex($bytes));
    }

    private function pkcs5Pad(string $text): string
    {
        $blockSize = 16;
        $pad = $blockSize - (strlen($text) % $blockSize);

        return $text.str_repeat(chr($pad), $pad);
    }

    private function pkcs5Unpad(string $text): string
    {
        $length = strlen($text);

        if ($length === 0) {
            return $text;
        }

        $pad = ord($text[$length - 1]);

        if ($pad < 1 || $pad > 16 || substr($text, -$pad) !== str_repeat(chr($pad), $pad)) {
            return $text;
        }

        return substr($text, 0, $length - $pad);
    }

    private function byteArrayToHex(array $bytes): string
    {
        return implode('', array_map(fn (int $b) => sprintf('%02x', $b), $bytes));
    }

    /**
     * @return list<int>
     */
    private function hexToByteArray(string $hex): array
    {
        $hex = preg_replace('/\s+/', '', $hex);
        $bytes = [];

        for ($i = 0; $i < strlen($hex); $i += 2) {
            $bytes[] = hexdec(substr($hex, $i, 2));
        }

        return $bytes;
    }

    private function byteArrayToString(array $bytes): string
    {
        return implode('', array_map(chr(...), $bytes));
    }

    private function toMajorUnits(int $amountMinor): float
    {
        return round($amountMinor / 100, 2);
    }
}
