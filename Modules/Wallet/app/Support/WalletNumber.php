<?php

namespace Modules\Wallet\Support;

/**
 * The public number of a wallet, used to receive transfers ("send to wallet
 * number"). A wallet is per (owner, country), so the number identifies exactly
 * *which* wallet gets the money — a person with a wallet in every country has
 * a different number in each, and a sender can never land funds in the wrong one.
 *
 * 10 random digits + a Luhn check digit (11 total): a mistyped number is
 * rejected as invalid before anyone is looked up, and the number carries no
 * meaning (no owner id, no country) so it leaks nothing.
 */
class WalletNumber
{
    public const LENGTH = 11;

    public static function generate(): string
    {
        $body = (string) random_int(1, 9);

        for ($i = 1; $i < self::LENGTH - 1; $i++) {
            $body .= random_int(0, 9);
        }

        return $body.self::checkDigit($body);
    }

    public static function isValid(string $number): bool
    {
        if (! preg_match('/^\d{'.self::LENGTH.'}$/', $number)) {
            return false;
        }

        return self::checkDigit(substr($number, 0, -1)) === (int) substr($number, -1);
    }

    /**
     * Whatever a person types or pastes ("123 4567 8901", Arabic-Indic digits,
     * dashes) → plain ASCII digits, or null when nothing usable is left.
     */
    public static function normalize(string $input): ?string
    {
        $latin = strtr($input, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $digits = preg_replace('/\D/', '', $latin);

        return $digits === '' ? null : $digits;
    }

    /**
     * What a wallet's QR code holds: `dorr://wallet/SA/12345678901`. Only public facts (the number and
     * its country) — scanning it does exactly what typing the number does, i.e. it opens the confirmation
     * screen showing who is behind it. It can never move money by itself.
     */
    public static function qrPayload(string $countryCode, string $number): string
    {
        return 'dorr://wallet/'.strtoupper($countryCode).'/'.$number;
    }

    /**
     * @return array{country: string, number: string}|null null when the text isn't one of our wallet QR codes
     */
    public static function parseQr(string $payload): ?array
    {
        if (! preg_match('#^dorr://wallet/([A-Za-z]{2})/(\d{'.self::LENGTH.'})$#', trim($payload), $m)) {
            return null;
        }

        return ['country' => strtoupper($m[1]), 'number' => $m[2]];
    }

    /** 12345678901 → "123 4567 8901" */
    public static function format(string $number): string
    {
        return substr($number, 0, 3).' '.substr($number, 3, 4).' '.substr($number, 7);
    }

    private static function checkDigit(string $body): int
    {
        $sum = 0;

        // Luhn: double every second digit starting from the rightmost of the body.
        foreach (array_reverse(str_split($body)) as $i => $digit) {
            $n = (int) $digit;

            if ($i % 2 === 0) {
                $n *= 2;

                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
