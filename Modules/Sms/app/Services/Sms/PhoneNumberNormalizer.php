<?php

namespace Modules\Sms\Services\Sms;

use App\Models\Country;
use Modules\Sms\Exceptions\SmsException;

/**
 * PhoneNumberNormalizer — country-driven E.164 normalization.
 *
 * dorr has no global phone normalizer, so normalization is resolved from the
 * COUNTRY the caller selected, using the existing catalog fields seeded from
 * database/seeders/data/country-phone-rules.json:
 *
 *   - Country::dial_code         stored WITH the plus sign (e.g. "+966")
 *   - Country::phone_length      length of the national significant number,
 *                                EXCLUDING the national trunk zero
 *                                (EG = 10, SA = 9, US = 10)
 *   - Country::phone_starts_with the first digit of the national significant
 *                                number (EG = 1, SA = 5, US = 2)
 *
 * Accepted inputs:
 *   - national: "01012345678", "1012345678", "010 123 45678"
 *   - E.164:    "+9661012345678"  (the redundant trunk zero is tolerated)
 *
 * A number that already carries "+" but belongs to a DIFFERENT country code is
 * rejected, so the selected country and the recipient can never disagree.
 */
class PhoneNumberNormalizer
{
    /**
     * Minimum plausible national significant number length (ITU-T E.164).
     */
    protected int $minNsnLength = 5;

    /**
     * Normalize a recipient for the given country, or throw a translated
     * SmsException explaining exactly what was wrong.
     *
     * @throws SmsException
     */
    public function normalize(string $to, Country $country): string
    {
        $to = trim($to);
        $wasInternational = str_starts_with($to, '+');
        $digits = preg_replace('/\D+/', '', $to) ?? '';

        if ($digits === '') {
            throw new SmsException(__('sms.accounts.invalid_recipient', ['phone' => $to]));
        }

        $dialCode = ltrim((string) $country->dial_code, '+');

        if ($dialCode === '') {
            throw new SmsException(__('sms.accounts.country_missing_dial_code'));
        }

        return $wasInternational
            ? $this->fromInternational($digits, $dialCode, $country, $to)
            : $this->fromNational($digits, $dialCode, $country, $to);
    }

    /**
     * The number already carried "+": it must belong to the selected country.
     */
    protected function fromInternational(string $digits, string $dialCode, Country $country, string $original): string
    {
        if (! str_starts_with($digits, $dialCode)) {
            throw new SmsException(__('sms.accounts.country_mismatch', ['phone' => $original]));
        }

        $nsn = substr($digits, strlen($dialCode));

        // "+966 0 1012345678" is a common typo — drop the redundant trunk zero.
        foreach ([preg_replace('/^0/', '', $nsn) ?? '', $nsn] as $candidate) {
            if ($this->matchesRule($candidate, $country)) {
                return '+'.$dialCode.$candidate;
            }
        }

        throw $this->lengthException($original, $country);
    }

    /**
     * A national number for the selected country: drop the national trunk zero
     * when present, then apply the country's dial code.
     */
    protected function fromNational(string $digits, string $dialCode, Country $country, string $original): string
    {
        // Candidate order: without the trunk zero first, then as typed. This
        // tolerates countries that keep a leading zero inside the NSN.
        foreach ([preg_replace('/^0/', '', $digits) ?? '', $digits] as $candidate) {
            if ($this->matchesRule($candidate, $country)) {
                return '+'.$dialCode.$candidate;
            }
        }

        throw $this->lengthException($original, $country);
    }

    /**
     * Does a national significant number satisfy the country's rule?
     */
    protected function matchesRule(string $nsn, Country $country): bool
    {
        if (strlen($nsn) < $this->minNsnLength) {
            return false;
        }

        $expectedLength = (int) $country->phone_length;

        if ($expectedLength > 0 && strlen($nsn) !== $expectedLength) {
            return false;
        }

        $startsWith = (string) $country->phone_starts_with;

        // "0" carries no information as a prefix, so it is not asserted.
        if ($startsWith !== '' && $startsWith !== '0' && ! str_starts_with($nsn, $startsWith)) {
            return false;
        }

        return true;
    }

    protected function lengthException(string $original, Country $country): SmsException
    {
        return new SmsException(__('sms.accounts.invalid_recipient_length', [
            'phone' => $original,
            'length' => (int) $country->phone_length,
            'starts_with' => (string) $country->phone_starts_with,
        ]));
    }
}
