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
}
