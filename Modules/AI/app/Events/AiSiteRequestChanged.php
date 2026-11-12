<?php

namespace Modules\AI\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A "build it with a person" request got a new message (customer, team or system - and every status move, payment
 * or refund writes a system message). Sent on the private channels that already exist for the people looking at it:
 * the customer (`Modules.User.Models.User.{id}` / provider) and the admins who handle requests
 * (`App.Models.Admin.{id}`). The payload only says which request changed; the open screen reloads it once - no polling.
 * After commit, so the reload sees the new rows; sent now, not queued.
 */
class AiSiteRequestChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const NAME = 'ai_site_request.changed';

    /** @param  list<string>  $channels  private channel names without the "private-" prefix */
    public function __construct(
        public readonly array $channels,
        public readonly int $requestId,
        public readonly ?int $messageId = null,
        public readonly ?string $sender = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new PrivateChannel($name), $this->channels);
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['request_id' => $this->requestId, 'message_id' => $this->messageId, 'sender' => $this->sender];
    }
}
