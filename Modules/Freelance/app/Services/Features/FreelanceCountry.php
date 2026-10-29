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
}
