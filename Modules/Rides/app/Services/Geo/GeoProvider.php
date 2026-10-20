<?php

namespace Modules\Rides\Services\Geo;

/**
 * The maps provider behind every route and city name the server asks for (Google today — the
 * owner's decision — swappable later without touching the callers). The app binds
 * ResilientGeoProvider: Google first, Jawad's free estimate when Google can't answer.
 */
interface GeoProvider
{
    /**
     * The driving route through the points, or null when it can't be had.
     *
     * @param  list<array{0: float, 1: float}>  $stops  [lat, lng] between origin and destination, in order.
     */
    public function route(float $originLat, float $originLng, float $destinationLat, float $destinationLng, array $stops = []): ?RouteResult;

    /** The city (locality) name of a point, in Arabic, or null when unknown. */
    public function cityName(float $lat, float $lng): ?string;
}
