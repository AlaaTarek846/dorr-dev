<?php

namespace Modules\Sports\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A live update on public channels — `sports.match.{uuid}` (the open match card) and
 * `sports.live` (lists) — one Pusher call for everyone watching; the apps never poll the provider.
 */
class SportsRealtimeEvent implements ShouldBroadcastNow
{
    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $channels, public readonly string $event, public readonly array $payload) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new Channel($name), $this->channels);
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
