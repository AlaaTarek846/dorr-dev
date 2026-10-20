<?php

namespace Modules\Rides\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * `EnsureRideFeature::class.':multi_stop'` — refuses with `403 rides_feature_disabled` when the
 * feature is off where the request happens: the city of `lat`/`lng` if sent, else the account's
 * country (docs/rides-plan.md §5). Even an old app that still shows the button can't use it.
 */
class EnsureRideFeature
{
    public function __construct(
        private readonly RideFeatures $features,
        private readonly CityResolver $cities,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        [$countryId, $cityId] = RideLocation::of($request, $this->cities);

        if ($countryId === null || ! $this->features->allows($countryId, $cityId, $feature)) {
            throw RidesException::featureDisabled($feature);
        }

        return $next($request);
    }
}
