<?php

namespace Modules\Rides\Services\Geo;

/**
 * A driving route: total metres and seconds, and the encoded polyline both apps draw.
 * `estimated` = Google couldn't answer and this is the free straight-line estimate (no polyline).
 */
final class RouteResult
{
    /**
     * @param  list<array{distance_m: int, duration_s: int}>  $legs  One per stretch between two points.
     */
    public function __construct(
        public readonly int $distanceM,
        public readonly int $durationS,
        public readonly ?string $polyline,
        public readonly array $legs = [],
        public readonly bool $estimated = false,
    ) {}
}
