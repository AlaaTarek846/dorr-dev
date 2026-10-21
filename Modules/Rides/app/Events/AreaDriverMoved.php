<?php

namespace Modules\Rides\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A free captain moved (docs/rides_tasks.md O-59): the riders' maps around show the cars moving, like
 * inDrive. A public channel per area cell (~5.5 km) — no name, no number, no real id in it: a short
 * per-day alias, the position and the heading. `gone` = the car left (offline, a ride started).
 */
class AreaDriverMoved implements ShouldBroadcastNow
{
    /** Cell size in degrees (~5.5 km). */
    public const CELL = 0.05;

    public function __construct(
        public readonly string $cell,
        public readonly string $car,
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?int $heading,
        public readonly bool $gone = false,
    ) {}

    public static function cellOf(float $lat, float $lng): string
    {
        return (int) floor($lat / self::CELL).'_'.(int) floor($lng / self::CELL);
    }

    /** The car's public alias: stable for a day, never the driver's id. */
    public static function alias(int $driverId): string
    {
        return substr(hash('sha256', $driverId.'|'.now()->toDateString().'|'.config('app.key')), 0, 12);
    }

    public function broadcastOn(): Channel
    {
        return new Channel('rides-area.'.$this->cell);
    }

    public function broadcastAs(): string
    {
        return 'area.car';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['car' => $this->car, 'lat' => round($this->lat, 5), 'lng' => round($this->lng, 5), 'heading' => $this->heading, 'gone' => $this->gone];
    }
}
