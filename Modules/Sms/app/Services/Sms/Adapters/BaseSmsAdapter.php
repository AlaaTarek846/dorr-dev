<?php

namespace Modules\Sms\Services\Sms\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Sms\Contracts\Sms\SmsProviderInterface;

/**
 * BaseSmsAdapter — shared HTTP + normalization helpers for SMS provider
 * adapters. All requests flow through the Laravel Http client so tests can
 * fake provider endpoints. Concrete adapters only declare identity/schema/
 * capabilities and provider-specific request/response handling.
 */
abstract class BaseSmsAdapter implements SmsProviderInterface
{
    /**
     * HTTP client with sane defaults (no exceptions on 4xx/5xx).
     */
    protected function http()
    {
        return Http::timeout(15)->connectTimeout(10);
    }

    /**
     * True when the given config binds a test / sandbox environment and the
     * provider only implements a real vs sandbox switch.
     */
    protected function isTestModeEnabled(array $config): bool
    {
        return ! empty($config['test_mode']) || (($config['environment'] ?? 'live') === 'test');
    }

    /**
     * Shared, secret-safe error normalization from an HTTP status/body pair.
     */
    protected function normalizeHttpError(int $status, string|callable|null $body = null, string $fallback = 'SMS provider error'): string
    {
        $bodyMessage = is_callable($body) ? $body() : (string) ($body ?? '');
        $message = $this->stripSensitive($bodyMessage);

        return $message !== '' ? $message : ($fallback.($status ? " (HTTP $status)" : ''));
    }

    /**
     * Remove likely secret tokens from an error string as a final safety net.
     */
    protected function stripSensitive(string $message): string
    {
        return preg_replace('/(token|apikey|api_key|password|secret|auth)\W+[A-Za-z0-9_\-\.=]{6,}/i', '$1 ****', $message) ?: $message;
    }

    /**
     * Log a non-sensitive debug line (context is deliberately empty of secrets).
     */
    protected function logDebug(string $message, array $context = []): void
    {
        Log::channel(config('sms.log_channel', 'stack'))->debug($message, $context);
    }
}
