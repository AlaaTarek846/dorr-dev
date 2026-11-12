<?php

namespace Modules\Marketing\Services\Features;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Provider\Models\Provider;
use Modules\User\Models\User;

/**
 * The country the switches and prices are read for. Read from the account, never from a header: a user's current
 * country (`logged_in_country_id`) then the one they registered with; a provider's account country.
 */
class MarketingCountry
{
    public static function of(?Authenticatable $who): ?int
    {
        return match (true) {
            $who instanceof User => $who->logged_in_country_id ?? $who->country_id,
            $who instanceof Provider => $who->country_id,
            default => null,
        };
    }
}
