<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\AI\Models\AiConversation;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| ai-conversation.{conversationId}: the final assistant reply for a chat
| conversation (Modules\AI\Events\AiMessageBroadcast) is only ever
| broadcast to that conversation's own owner (customer or provider) -
| never to any other authenticated user or provider, matching the same
| ownership check AiConversationRepository::findForOwner() already
| enforces on the HTTP side.
|
*/

Broadcast::channel('ai-conversation.{conversationId}', function ($user, int $conversationId) {
    if (! $user) {
        return false;
    }

    $conversation = AiConversation::query()->find($conversationId);

    if (! $conversation) {
        return false;
    }

    return $conversation->owner_type === $user->getMorphClass()
        && (string) $conversation->owner_id === (string) $user->getAuthIdentifier();
});
