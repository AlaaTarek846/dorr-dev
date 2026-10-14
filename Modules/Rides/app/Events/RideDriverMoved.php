<?php

namespace Modules\Rides\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** The driver's latest position, relayed to the rider while a driver is on the ride (the moving car). */
class RideDriverMoved implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $uuid,
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?int $heading,
        public readonly ?float $speedMps,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('rides.'.$this->uuid);
    }

    public function broadcastAs(): string
    {
        return 'ride.driver_location';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng, 'heading' => $this->heading, 'speed_mps' => $this->speedMps, 'at' => now()->toIso8601String()];
    }
}
