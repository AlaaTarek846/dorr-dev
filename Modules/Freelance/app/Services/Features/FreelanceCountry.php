<?php

namespace Modules\Freelance\Services\Features;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Freelance\Models\Freelancer;
use Modules\User\Models\User;

/**
 * The country the switches are read for: a freelancer's account country; a client's current country
 * (`logged_in_country_id`, the place they sign in from) and, without it, the one they registered with.
 */
class FreelanceCountry
{
    public static function of(?Authenticatable $who): ?int
    {
        return match (true) {
            $who instanceof Freelancer => $who->country_id,
            $who instanceof User => $who->logged_in_country_id ?? $who->country_id,
            default => null,
        };
    }

    /** @var array<int, array{code: string, name: string}|null> */
    private static array $cards = [];

    /**
     * `{code, name}` of a country, for the apps to show where a project, a service, a consultation or a post comes from now that
     * freelance work is open across countries (owner, 2026-11-04). Read once a request per country; null when unknown.
     *
     * @return array{code: string, name: string}|null
     */
    public static function card(?int $countryId): ?array
    {
        if ($countryId === null) {
            return null;
        }
        $key = $countryId.'|'.app()->getLocale();
        if (! array_key_exists($key, self::$cards)) {
            $c = \App\Models\Country::query()->with('translations')->find($countryId);
            self::$cards[$key] = $c === null ? null : ['code' => (string) $c->code, 'name' => (string) ($c->translatedName() ?? $c->code)];
        }

        return self::$cards[$key];
    }
}
