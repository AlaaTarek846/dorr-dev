<?php

namespace Modules\Wallet\Support\Payments;

/**
 * Defense in depth for payment_gateway_logs / payment_transactions.raw_*:
 * the gateway drivers already avoid putting secrets in the payloads they
 * hand back, this strips anything secret-looking that slipped through so a
 * log row can never leak a credential (docs/wallet-structure.md §9.2).
 */
class LogSanitizer
{
    private const REDACT_KEYS = [
        'password', 'passwd', 'secret', 'api_key', 'apikey', 'token', 'authorization',
        'tranportal_password', 'tranportal_resource_key', 'trandata',
        'x-security-token', 'x-verification-token', 'otp', 'pin', 'credentials',
    ];

    /**
     * @param  array<mixed>|null  $payload
     * @return array<mixed>|null
     */
    public static function redact(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }

    private static function isSecretKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::REDACT_KEYS as $secret) {
            if ($key === $secret || str_ends_with($key, '_'.$secret) || str_ends_with($key, '-'.$secret)) {
                return true;
            }
        }

        return false;
    }
}
