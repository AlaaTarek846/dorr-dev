<?php

namespace Modules\Rides\Http\Middleware;

use Illuminate\Http\Request;
use Modules\Rides\Services\Geo\CityResolver;

/**
 * Where a request happens, for the feature switches: the city of `lat`/`lng` when the app sends
 * them (and that city's country), otherwise the account's own country with no city.
 */
final class RideLocation
{
    /** @return array{0: ?int, 1: ?int} [countryId, cityId] */
    public static function of(Request $request, CityResolver $cities): array
    {
        $lat = $request->input('lat');
        $lng = $request->input('lng');

        if (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
            $cityId = $cities->cityAt((float) $lat, (float) $lng);
            if ($cityId !== null) {
                return [$cities->countryOf($cityId), $cityId];
            }
        }

        $countryId = $request->user()?->getAttribute('country_id');

        return [$countryId !== null ? (int) $countryId : null, null];
    }
}
