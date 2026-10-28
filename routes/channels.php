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

Broadcast::routes(['middleware' => ['auth:admin_api,user_api,provider_api,driver_api,freelance_api']]);

Broadcast::channel('App.Models.Admin.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
}, ['guards' => ['admin_api']]);

Broadcast::channel('Modules.User.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
}, ['guards' => ['user_api']]);

Broadcast::channel('Modules.Provider.Models.Provider.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
}, ['guards' => ['provider_api']]);

Broadcast::channel('Modules.Driver.Models.Driver.{id}', function ($driver, $id) {
    return (int) $driver->id === (int) $id;
}, ['guards' => ['driver_api']]);

// A passenger ride (Modules/Rides): its rider and its current driver follow the status and the car.
Broadcast::channel('rides.{uuid}', function ($person, string $uuid) {
    $ride = \Modules\Rides\Models\Ride::query()->where('uuid', $uuid)->first(['user_id', 'driver_id']);
    if ($ride === null) {
        return false;
    }

    return $person instanceof \Modules\Driver\Models\Driver
        ? (int) $ride->driver_id === (int) $person->id
        : $person instanceof \Modules\User\Models\User && (int) $ride->user_id === (int) $person->id;
}, ['guards' => ['user_api', 'driver_api']]);

// A client-freelancer room (Modules/Freelance, FL-006): only its two people listen.
Broadcast::channel('freelance-chat.{uuid}', function ($person, string $uuid) {
    $room = \Modules\Freelance\Models\FreelanceConversation::query()->where('uuid', $uuid)->first(['client_id', 'freelancer_id']);
    if ($room === null) {
        return false;
    }

    return $person instanceof \Modules\Freelance\Models\Freelancer
        ? (int) $room->freelancer_id === (int) $person->id
        : $person instanceof \Modules\User\Models\User && (int) $room->client_id === (int) $person->id;
}, ['guards' => ['user_api', 'freelance_api']]);

// The freelancer's own channel: every live notification reaches them here (Modules/Freelance, FL-007).
Broadcast::channel('Modules.Freelance.Models.Freelancer.{id}', function ($freelancer, $id) {
    return (int) $freelancer->id === (int) $id;
}, ['guards' => ['freelance_api']]);
