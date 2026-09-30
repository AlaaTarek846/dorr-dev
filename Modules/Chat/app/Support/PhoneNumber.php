<?php

namespace Modules\Chat\Support;

use App\Models\Country;

/**
 * Turns a phone number as it appears in an address book ("0501 234 567", "+966 50 123 4567",
 * "00966501234567") into the E.164 form accounts are stored with ("+966501234567"), using the
 * owner's country for numbers written without a country code.
 */
class PhoneNumber
{
    public static function toE164(string $raw, ?Country $defaultCountry): ?string
    {
        $raw = trim($raw);
        $digits = preg_replace('/\D+/', '', strtr($raw, '٠١٢٣٤٥٦٧٨٩', '0123456789')) ?? '';

        if ($digits === '' || strlen($digits) < 6 || strlen($digits) > 15) {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        $dial = ltrim((string) $defaultCountry?->dial_code, '+');

        if ($dial === '') {
            return null;
        }

        // Already carries the country code, just without the "+".
        $length = $defaultCountry?->phone_length !== null ? (int) $defaultCountry->phone_length : null;
        if ($length !== null && strlen($digits) === strlen($dial) + $length && str_starts_with($digits, $dial)) {
            return '+'.$digits;
        }

        // National format: drop the trunk "0".
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '+'.$dial.$digits;
    }

    /**
     * The active country whose dial code starts this E.164 number (longest code wins: +1 vs +1242).
     */
    public static function countryOf(string $e164): ?Country
    {
        $digits = ltrim($e164, '+');

        return Country::query()->where('status', true)->whereNotNull('dial_code')->get(['id', 'code', 'dial_code', 'phone_length', 'phone_starts_with'])
            ->filter(fn (Country $c) => ($dial = ltrim((string) $c->dial_code, '+')) !== '' && str_starts_with($digits, $dial))
            ->sortByDesc(fn (Country $c) => strlen(ltrim((string) $c->dial_code, '+')))
            ->first();
    }

    /**
     * A complete number for that country: the national part has exactly `phone_length` digits and
     * starts with one of `phone_starts_with` (comma separated) when the country sets them.
     */
    public static function fits(string $e164, Country $country): bool
    {
        $national = substr(ltrim($e164, '+'), strlen(ltrim((string) $country->dial_code, '+')));

        if ($country->phone_length !== null && strlen($national) !== (int) $country->phone_length) {
            return false;
        }

        $prefixes = array_filter(array_map('trim', explode(',', (string) $country->phone_starts_with)));

        return $prefixes === [] || collect($prefixes)->contains(fn (string $p) => str_starts_with($national, $p));
    }
}
