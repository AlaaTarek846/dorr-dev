<?php

namespace Modules\Rides\Support;

/**
 * Compares place names written in slightly different ways (Google vs our lists): spaces, tatweel,
 * diacritics, the definite article's hamza forms and letter case. Only for matching — the name
 * actually sent anywhere is always the stored one, untouched.
 */
final class PlaceName
{
    public static function key(?string $name): string
    {
        $name = (string) $name;
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $name) ?? $name;   // diacritics, tatweel
        $name = strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? $name;

        return mb_strtolower($name);
    }

    public static function same(?string $a, ?string $b): bool
    {
        return self::key($a) !== '' && self::key($a) === self::key($b);
    }
}
