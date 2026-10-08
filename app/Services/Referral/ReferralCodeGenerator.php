<?php

namespace App\Services\Referral;

class ReferralCodeGenerator
{
    public function make(): string
    {
        $prefix = (string) config('referral.prefix', 'DORRFC-');
        $length = (int) config('referral.suffix_length', 6);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $suffix = '';

        for ($i = 0; $i < $length; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $prefix.$suffix;
    }

    public static function isValidFormat(string $code): bool
    {
        $prefix = preg_quote((string) config('referral.prefix', 'DORRFC-'), '/');
        $length = (int) config('referral.suffix_length', 6);

        return (bool) preg_match('/^'.$prefix.'[A-Z0-9]{'.$length.'}$/', strtoupper(trim($code)));
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }
}
