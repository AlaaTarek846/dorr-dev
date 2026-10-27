<?php

namespace Modules\Rides\Services\Drivers;

use Modules\Driver\Models\Driver;
use Modules\Driver\Models\Vehicle;
use Modules\Rides\Enums\PricingStrategy;
use Modules\Rides\Models\RideCategory;

/**
 * O-85: what a captain's vehicle can do. A car takes passenger rides (Economy, VIP, With Me…), a
 * motorcycle only the Motorcycle category, a bus only bus trips, a boat / yacht only the sea, and a
 * captain with no vehicle only drives a rider's car or teaches. A minibus is both a car and a bus.
 * This holds whatever a category's admin rules say — a bus never gets an Economy request.
 */
class VehicleKinds
{
    public const CAR = 'car';

    public const MOTORCYCLE = 'motorcycle';

    public const BUS = 'bus';

    public const MARINE = 'marine';

    public const NONE = 'none';

    /** What each kind of vehicle may be used for, in the app's words. */
    private const SERVICES = [
        self::CAR => ['rides', 'with_me', 'scheduled', 'airport', 'hire', 'lessons'],
        self::MOTORCYCLE => ['rides', 'scheduled'],
        self::BUS => ['bus'],
        self::MARINE => ['marine'],
        self::NONE => ['hire', 'lessons'],
    ];

    /** @return list<string> the kinds a body type counts as */
    public static function ofBody(?string $body): array
    {
        return match ($body) {
            null => [],
            'motorcycle' => [self::MOTORCYCLE],
            'bus' => [self::BUS],
            'minibus' => [self::CAR, self::BUS],
            'boat', 'yacht' => [self::MARINE],
            default => [self::CAR],
        };
    }

    /** The kind of vehicle a category is for. */
    public static function ofCategory(RideCategory $category): string
    {
        return match (true) {
            $category->slug === 'motorcycle' => self::MOTORCYCLE,
            $category->slug === 'bus' || $category->pricing_strategy === PricingStrategy::Seat => self::BUS,
            $category->slug === 'marine' || $category->pricing_strategy === PricingStrategy::Time => self::MARINE,
            default => self::CAR,
        };
    }

    public static function fits(Vehicle $vehicle, RideCategory $category): bool
    {
        $body = $vehicle->body_type instanceof \BackedEnum ? $vehicle->body_type->value : $vehicle->body_type;

        return in_array(self::ofCategory($category), self::ofBody($body), true);
    }

    /** @return list<string> the captain's kinds: from the active vehicle, else the latest one, else none */
    public function of(Driver $driver): array
    {
        $vehicle = app(DriverEligibility::class)->activeVehicle($driver) ?? $driver->vehicles()->latest('id')->first();
        if ($vehicle === null) {
            return [self::NONE];
        }

        return self::ofBody($vehicle->body_type instanceof \BackedEnum ? $vehicle->body_type->value : $vehicle->body_type) ?: [self::NONE];
    }

    /** @return list<string> the services this captain's app shows (O-85) */
    public function services(Driver $driver): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (string $k) => self::SERVICES[$k], $this->of($driver)))));
    }

    public function offers(Driver $driver, string $service): bool
    {
        return in_array($service, $this->services($driver), true);
    }
}
