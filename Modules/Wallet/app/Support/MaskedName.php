<?php

namespace Modules\Wallet\Support;

/**
 * How another person's name is shown before/after a transfer: the first letter
 * of each word, the rest hidden — "سارة أحمد محمد" → "س*** أ*** م***". Enough to
 * recognise who you are paying, not enough to harvest names.
 */
class MaskedName
{
    public static function of(?string $name): string
    {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return '***';
        }

        return implode(' ', array_map(fn (string $word) => mb_substr($word, 0, 1).'***', $words));
    }
}
