<?php

namespace Modules\User\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A live change in a support ticket (new ticket, new message, new status), delivered at once on the
 * private channels of everyone looking at it: the customer (`Modules.User.Models.User.{id}`, the
 * channel the app already listens on) and the support admins (`App.Models.Admin.{id}`, the channel
 * the dashboard already listens on). Sent now, not queued: a reply that arrives late is a broken chat.
 */
class SupportRealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    public const MESSAGE = 'support.message';

    public const TICKET_CREATED = 'support.ticket.created';

    public const TICKET_UPDATED = 'support.ticket.updated';

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
