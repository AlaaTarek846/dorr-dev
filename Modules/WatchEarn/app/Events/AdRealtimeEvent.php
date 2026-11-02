<?php

namespace Modules\WatchEarn\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * What everyone watching one ad sees live (a new comment, the reaction counts) — on `private-watch-earn.ad.{uuid}`,
 * which any signed-in user may join (comments are seen by every viewer, D4). Through Laravel Broadcasting only.
 */
class AdRealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly string $adUuid,
        public readonly string $event,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('watch-earn.ad.'.$this->adUuid)];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
