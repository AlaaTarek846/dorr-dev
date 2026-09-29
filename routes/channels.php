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

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::routes(['middleware' => ['auth:admin_api,user_api,provider_api']]);

Broadcast::channel('App.Models.Admin.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
}, ['guards' => ['admin_api']]);

Broadcast::channel('Modules.User.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
}, ['guards' => ['user_api']]);

Broadcast::channel('Modules.Provider.Models.Provider.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
}, ['guards' => ['provider_api']]);
