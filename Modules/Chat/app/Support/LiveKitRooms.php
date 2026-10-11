<?php

namespace Modules\Chat\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LiveKit's RoomService (its Twirp HTTP API), for what the server decides in a live room: who may
 * speak, who is put out. Best-effort — a LiveKit hiccup never fails our own bookkeeping (the
 * app also follows our `chat.voice.updated` event and re-joins with a fresh token).
 */
class LiveKitRooms
{
    /** Let someone speak (publish) or only listen — at once, without them re-joining. */
    public function setCanPublish(string $room, string $identity, bool $canPublish): bool
    {
        return $this->call($room, 'UpdateParticipant', [
            'room' => $room,
            'identity' => $identity,
            'permission' => ['can_subscribe' => true, 'can_publish' => $canPublish, 'can_publish_data' => true],
        ]);
    }

    public function remove(string $room, string $identity): bool
    {
        return $this->call($room, 'RemoveParticipant', ['room' => $room, 'identity' => $identity]);
    }

    /** @param  array<string, mixed>  $body */
    private function call(string $room, string $method, array $body): bool
    {
        $url = LiveKitToken::url();
        if ($url === null) {
            return false;
        }
        $base = preg_replace('#^ws(s?)://#', 'http$1://', rtrim($url, '/'));
        try {
            return Http::withToken(LiveKitToken::admin($room))->acceptJson()->timeout(5)
                ->post($base.'/twirp/livekit.RoomService/'.$method, $body)->successful();
        } catch (Throwable $e) {
            Log::warning('[chat] LiveKit '.$method.': '.$e->getMessage());

            return false;
        }
    }
}
