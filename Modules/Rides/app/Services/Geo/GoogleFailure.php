<?php

namespace Modules\Rides\Services\Geo;

/**
 * Puts Google's refusals into the few kinds that matter to whoever has to fix them. Google says the
 * same thing in many ways — an HTTP status and a status word (Routes API), or `status` /
 * `error_message` inside a 200 (Geocoding) — so the kind is read from all of them together.
 */
final class GoogleFailure
{
    public const KEY = 'key';                 // the key is wrong or was deleted

    public const API_DISABLED = 'api_disabled'; // the key is fine but this API is not switched on for its project

    public const BILLING = 'billing';         // billing is not enabled / was closed

    public const RESTRICTED = 'restricted';   // the key is restricted to apps / sites / IPs / other APIs

    public const QUOTA = 'quota';             // used up (daily / per-minute)

    public const OTHER = 'other';

    public static function classify(?int $http, ?string $status, ?string $message): string
    {
        $text = mb_strtolower(trim(($status ?? '').' '.($message ?? '')));

        return match (true) {
            str_contains($text, 'billing') => self::BILLING,
            str_contains($text, 'quota') || str_contains($text, 'resource_exhausted')
                || str_contains($text, 'over_query_limit') || str_contains($text, 'over_daily_limit') || $http === 429 => self::QUOTA,
            str_contains($text, 'api key not valid') || str_contains($text, 'key is invalid') || str_contains($text, 'api_key_invalid')
                || str_contains($text, 'key not found') => self::KEY,
            str_contains($text, 'has not been used') || str_contains($text, 'is disabled') || str_contains($text, 'service_disabled')
                || str_contains($text, 'project is not authorized') || str_contains($text, 'not been enabled') => self::API_DISABLED,
            str_contains($text, 'referer') || str_contains($text, 'ip address') || str_contains($text, 'not authorized to use this api key')
                || str_contains($text, 'blocked') || str_contains($text, 'android') || str_contains($text, 'restriction') => self::RESTRICTED,
            default => self::OTHER,
        };
    }

    /** Refusals that will not fix themselves in a minute — Google is left alone for a while. */
    public static function isRefusal(string $kind): bool
    {
        return $kind !== self::OTHER;
    }
}
