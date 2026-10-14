<?php

namespace Modules\Rides\Services\Regulator;

use Illuminate\Support\Collection;
use Modules\Rides\Models\RideRegulatorCity;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Geo\GeoProvider;
use Modules\Rides\Support\PlaceName;

/**
 * The exact city name the regulator accepts for a point (docs/rides-structure.md §7.2). Wasl
 * rejects a trip when one letter differs, so whatever we find, the name sent is always the
 * official one stored in `ride_regulator_cities`:
 *  1. Google's city name, if it matches an official name;
 *  2. otherwise our city for the point (CityResolver), through its admin mapping.
 * Null = no official name for that place yet: the trip waits in the admin, nothing is guessed.
 */
class RegulatorCityNames
{
    public function __construct(
        private readonly GeoProvider $geo,
        private readonly CityResolver $cities,
    ) {}

    public function officialName(string $regulator, float $lat, float $lng): ?string
    {
        $names = $this->names($regulator);
        if ($names->isEmpty()) {
            return null;
        }

        $google = $this->geo->cityName($lat, $lng);
        if ($google !== null && ($match = $names->first(fn (RideRegulatorCity $c) => PlaceName::same($c->official_name, $google))) !== null) {
            return $match->official_name;
        }

        $cityId = $this->cities->cityAt($lat, $lng);

        return $cityId === null ? null : $names->firstWhere('city_id', $cityId)?->official_name;
    }

    /** @return Collection<int, RideRegulatorCity> */
    private function names(string $regulator): Collection
    {
        return RideRegulatorCity::query()->where('regulator', $regulator)->where('status', true)->get();
    }
}
