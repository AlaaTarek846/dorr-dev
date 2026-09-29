<?php

namespace Modules\Wallet\Support;

/**
 * Canonical SHA-256 of a request payload, used alongside idempotency_key
 * (docs/wallet-structure.md §0): same key + same hash → replay the old
 * result; same key + different hash → 409, something is reusing a key
 * incorrectly.
 */
class RequestHasher
{
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::sortRecursively($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function sortRecursively(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::sortRecursively($value);
            }
        }

        return $payload;
    }
}
