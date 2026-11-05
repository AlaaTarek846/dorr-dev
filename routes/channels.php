<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\AI\Models\AiConversation;
use Modules\Driver\Models\Driver;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\Freelancer;
use Modules\Moving\Models\MovingRequest;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Provider;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\Rides\Models\Ride;
use Modules\User\Models\User;
use Modules\WatchEarn\Models\Ad;

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
    $ride = Ride::query()->where('uuid', $uuid)->first(['user_id', 'driver_id']);
    if ($ride === null) {
        return false;
    }

    return $person instanceof Driver
        ? (int) $ride->driver_id === (int) $person->id
        : $person instanceof User && (int) $ride->user_id === (int) $person->id;
}, ['guards' => ['user_api', 'driver_api']]);

// A move (Modules/Moving): its customer, and in its establishment the assigned driver and whoever may follow the jobs.
Broadcast::channel('moving.{uuid}', function ($person, string $uuid) {
    $move = MovingRequest::query()->where('uuid', $uuid)->first(['user_id', 'establishment_id', 'branch_id', 'driver_membership_id']);
    if ($move === null) {
        return false;
    }
    if ($person instanceof User) {
        return (int) $move->user_id === (int) $person->id;
    }
    if (! $person instanceof Provider || $move->establishment_id === null) {
        return false;
    }
    $access = app(EstablishmentAccess::class);
    $branch = Branch::query()->find($move->branch_id);

    return $person->memberships()->where('establishment_id', $move->establishment_id)->where('status', 'active')->get()
        ->contains(fn ($m) => (int) $m->id === (int) $move->driver_membership_id || $access->can($m, 'jobs.track', $branch));
}, ['guards' => ['user_api', 'provider_api']]);

// A client-freelancer room (Modules/Freelance, FL-006): only its two people listen.
Broadcast::channel('freelance-chat.{uuid}', function ($person, string $uuid) {
    $room = FreelanceConversation::query()->where('uuid', $uuid)->first(['client_id', 'freelancer_id']);
    if ($room === null) {
        return false;
    }

    return $person instanceof Freelancer
        ? (int) $room->freelancer_id === (int) $person->id
        : $person instanceof User && (int) $room->client_id === (int) $person->id;
}, ['guards' => ['user_api', 'freelance_api']]);

// The freelancer's own channel: every live notification reaches them here (Modules/Freelance, FL-007).
Broadcast::channel('Modules.Freelance.Models.Freelancer.{id}', function ($freelancer, $id) {
    return (int) $freelancer->id === (int) $id;
}, ['guards' => ['freelance_api']]);

// Watch & Earn: everyone with an ad open hears its new comments and reaction counts (comments are seen by every
// viewer — docs/watch_earn_tasks.md D4). Any signed-in user, for an ad that exists.
Broadcast::channel('watch-earn.ad.{uuid}', function ($user, string $uuid) {
    return $user instanceof User
        && Ad::query()->where('uuid', $uuid)->exists();
}, ['guards' => ['user_api']]);
