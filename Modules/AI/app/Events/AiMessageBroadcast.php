<?php

namespace Modules\AI\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\AI\Http\Resources\AiMessageResource;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiMessage;

/**
 * v2.0 requirements doc S15.3: pushes the final assistant reply over
 * Reverb the moment it is persisted, so any other open tab/session on
 * the same conversation (or a future provider-side mirror view) sees it
 * immediately rather than needing to poll or refresh - AiChatService's
 * own HTTP response already delivers the reply synchronously to the tab
 * that sent the message, so this event's value is for everyone else
 * watching the same conversation.
 *
 * ShouldBroadcastNow (not the queued ShouldBroadcast) so this works out
 * of the box without requiring a queue worker to be running - the guard
 * in AiChatService::broadcastAssistantMessage() also ensures a
 * Reverb-connection failure can never break the chat response itself.
 */
class AiMessageBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public AiConversation $conversation,
        public AiMessage $message,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('ai-conversation.'.$this->conversation->id)];
    }

    public function broadcastAs(): string
    {
        return 'message.ready';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'message' => (new AiMessageResource($this->message))->resolve(),
        ];
    }
}
