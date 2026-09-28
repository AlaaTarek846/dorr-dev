<?php

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Chat\Events\ChatRealtimeEvent;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\ParticipantType;
use Throwable;

/**
 * Real-time delivery for the chat. Goes through Laravel Broadcasting only (never the Pusher SDK
 * directly), so moving from Pusher to our own Soketi/Reverb server later is a config change
 * (docs/chat-plan.md §9.2).
 *
 * Sends after the surrounding transaction commits (a rolled-back message must never appear on
 * someone's screen) and never fails the action that caused it.
 */
class ChatBroadcaster
{
    /**
     * @param  iterable<ChatParticipant>  $participants
     * @param  array<string, mixed>  $payload
     */
    public function toParticipants(iterable $participants, string $event, array $payload, ?ChatParticipant $except = null): void
    {
        $channels = [];

        foreach ($participants as $participant) {
            if ($except !== null && $participant->id === $except->id) {
                continue;
            }
            $channels[] = self::channelFor($participant->participant_type, (int) $participant->participant_id);
        }

        $this->send(array_values(array_unique($channels)), $event, $payload);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $accounts  [alias, id] pairs
     * @param  array<string, mixed>  $payload
     */
    public function toAccounts(array $accounts, string $event, array $payload): void
    {
        $this->send(array_values(array_unique(array_map(fn ($a) => self::channelFor($a[0], (int) $a[1]), $accounts))), $event, $payload);
    }

    /**
     * "Modules.User.Models.User.7" — the account's own private channel (routes/channels.php).
     */
    public static function channelFor(string $alias, int $id): string
    {
        return str_replace('\\', '.', ParticipantType::modelClassFor($alias)).'.'.$id;
    }

    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $payload
     */
    private function send(array $channels, string $event, array $payload): void
    {
        if ($channels === []) {
            return;
        }

        DB::afterCommit(function () use ($channels, $event, $payload) {
            try {
                event(new ChatRealtimeEvent($channels, $event, $payload));
            } catch (Throwable $e) {
                Log::error('[ChatBroadcaster] '.$e->getMessage(), ['event' => $event]);
            }
        });
    }
}
