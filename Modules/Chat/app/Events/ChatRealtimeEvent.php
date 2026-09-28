<?php

namespace Modules\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One real-time chat event, delivered on each recipient's own private channel
 * (`Modules.User.Models.User.{id}` — the channel the apps already listen on).
 *
 * One event object → one Pusher call per 100 channels (the broadcaster chunks), instead of one
 * call per member as a Notification would. Sent "now" (not queued) because a chat message that
 * arrives late is a broken chat.
 */
class ChatRealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<string>  $channels  private channel names without the "private-" prefix
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $event,
        public readonly array $payload,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new PrivateChannel($name), $this->channels);
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
