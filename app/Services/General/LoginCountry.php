<?php

namespace App\Services\General;

use App\Models\Country;

/**
 * The country a sign-in comes from: looked up by IP once, at sign-in, and kept on the account
 * (`users.logged_in_country_id`) — CountryResolver reads it from there afterwards instead of
 * asking getCountryCodeByIp() again. A country we don't have (or have switched off) becomes
 * Saudi Arabia, the platform's home country; failing that, the seeded default.
 */
class LoginCountry
{
    public const FALLBACK_CODE = 'SA';

    public function detect(): ?Country
    {
        $active = fn (?string $code): ?Country => $code
            ? Country::query()->where('code', strtoupper($code))->where('status', true)->first()
            : null;

        return $active(getCountryCodeByIp())
            ?? $active(self::FALLBACK_CODE)
            ?? Country::query()->where('is_default', true)->where('status', true)->first();
    }

    /**
     * Saves it on the account — called on every sign-in (OTP, password, social, registration, reset).
     */
    public function remember(object $user): void
    {
        if (! isset($user->id) || ! method_exists($user, 'forceFill')) {
            return;
        }

        $country = $this->detect();

        if ($country !== null && $user->logged_in_country_id !== $country->id) {
            $user->forceFill(['logged_in_country_id' => $country->id])->saveQuietly();
        }
    }
}
