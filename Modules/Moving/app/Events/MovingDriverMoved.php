<?php

namespace Modules\Moving\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** The truck's latest position on moving day, to the customer and the establishment (REQ-035). */
class MovingDriverMoved implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $uuid,
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?int $heading,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('moving.'.$this->uuid);
    }

    public function broadcastAs(): string
    {
        return 'moving.driver_location';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng, 'heading' => $this->heading, 'at' => now()->toIso8601String()];
    }
}
