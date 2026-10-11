<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\ChatVoiceRoom;
use Modules\Chat\Models\ChatVoiceRoomMember;
use Modules\Chat\Support\LiveKitRooms;
use Modules\Chat\Support\LiveKitToken;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * Standing voice rooms (spec 91): every group and channel has one, always there. Members drop in
 * and out — nobody rings anybody. Group admins (and the channel's admins) are hosts: they choose
 * whether everyone may speak or only those they let speak, move people between speaking and
 * listening, and put someone out. Who may speak is enforced by LiveKit itself (the token's
 * `canPublish`, changed live through LiveKit's RoomService), not only by the app.
 */
class VoiceRoomService
{
    /** No heartbeat for this long: out of the room. */
    public const STALE_SECONDS = 90;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ParticipantDirectory $directory,
        private readonly ChatBroadcaster $broadcaster,
        private readonly LiveKitRooms $livekit,
    ) {}

    public function roomOf(Model $me, ChatConversation $conversation): ChatVoiceRoom
    {
        $this->conversations->participantOf($me, $conversation, true);
        if (! $conversation->isGroup()) {
            throw new ChatException('voice_groups_only', 422);
        }

        return ChatVoiceRoom::query()->firstOrCreate(['conversation_id' => $conversation->id], [
            'uuid' => (string) Str::uuid(),
            'room_name' => 'voice-'.Str::uuid(),
            // A channel: its admins speak, followers listen (and raise a hand).
            'speak_policy' => $conversation->isChannel() ? 'approved' : 'everyone',
        ]);
    }

    /** @return array<string, mixed> the room, who's in it, my place in it */
    public function show(Model $me, ChatConversation $conversation): array
    {
        return $this->present($me, $this->roomOf($me, $conversation));
    }

    /** @return array{room: array<string, mixed>, join: array{url: string, room: string, token: string}} */
    public function join(Model $me, ChatConversation $conversation): array
    {
        $settings = ChatSetting::current();
        if (! $settings->calls_enabled) {
            throw ChatException::callsDisabled();
        }
        if (! $settings->callsAllowedFor($me)) {
            throw new ChatException('calls_unavailable_country', 403);
        }
        if (! app(ChatFeatures::class)->allows($me, 'voice_rooms')) {
            throw new ChatException('feature_unavailable', 403);
        }
        $room = $this->roomOf($me, $conversation);
        $mine = $this->conversations->participantOf($me, $conversation, true);
        $url = LiveKitToken::url() ?? throw ChatException::callsNotConfigured();

        $member = DB::transaction(function () use ($room, $mine) {
            $member = ChatVoiceRoomMember::query()->firstOrNew(['room_id' => $room->id, 'participant_id' => $mine->id]);
            $member->fill([
                'role' => $this->defaultRole($room, $mine),
                'hand_raised_at' => null,
                'joined_at' => now(),
                'left_at' => null,
                'seen_at' => now(),
            ])->save();
            if ($room->created_by_participant_id === null) {
                $room->created_by_participant_id = $mine->id;
            }
            $room->last_active_at = now();
            $room->save();

            return $member;
        });
        $this->changed($room);
        $profile = $this->directory->profile($me, $mine->participant_type, $mine->participant_id);

        return [
            'room' => $this->present($me, $room),
            'join' => [
                'url' => $url,
                'room' => $room->room_name,
                'token' => LiveKitToken::issue($room->room_name, $mine->key(), (string) ($profile['account_name'] ?? ''), [
                    'participant' => $mine->key(),
                    'avatar' => $profile['avatar'] ?? null,
                ], ['canPublish' => $member->canSpeak()]),
            ],
        ];
    }

    public function leave(Model $me, ChatVoiceRoom $room): void
    {
        $member = $this->memberOf($me, $room);
        if ($member !== null && $member->left_at === null) {
            $member->update(['left_at' => now(), 'hand_raised_at' => null]);
            $this->changed($room);
        }
    }

    /** I'm still here (every 30 s while connected). */
    public function ping(Model $me, ChatVoiceRoom $room): void
    {
        $this->memberOf($me, $room)?->update(['seen_at' => now()]);
    }

    public function hand(Model $me, ChatVoiceRoom $room, bool $raised): array
    {
        $member = $this->memberOf($me, $room) ?? throw new ChatException('voice_not_in_room', 409);
        $member->update(['hand_raised_at' => $raised ? now() : null]);
        $this->changed($room);

        return $this->present($me, $room);
    }

    /** A host lets someone speak, or makes them listen. */
    public function setRole(Model $me, ChatVoiceRoom $room, int $participantId, string $role): array
    {
        $this->assertHost($me, $room);
        $member = $room->members()->where('participant_id', $participantId)->firstOrFail();
        if ($member->role === 'host' && $role !== 'host') {
            throw new ChatException('voice_host_stays', 422);
        }
        $member->update(['role' => $role, 'hand_raised_at' => null]);
        $this->livekit->setCanPublish($room->room_name, $member->participant->key(), $role !== 'listener');
        $this->changed($room);

        return $this->present($me, $room);
    }

    /** A host puts someone out of the room (they can come back). */
    public function remove(Model $me, ChatVoiceRoom $room, int $participantId): array
    {
        $this->assertHost($me, $room);
        $member = $room->members()->where('participant_id', $participantId)->firstOrFail();
        $member->update(['left_at' => now(), 'hand_raised_at' => null]);
        $this->livekit->remove($room->room_name, $member->participant->key());
        $this->changed($room);

        return $this->present($me, $room);
    }

    /** @param  array{speak_policy?: string, title?: ?string}  $data */
    public function update(Model $me, ChatVoiceRoom $room, array $data): array
    {
        $this->assertHost($me, $room);
        $room->update(array_intersect_key($data, array_flip(['speak_policy', 'title'])));
        // Everyone may speak now: every listener becomes a speaker.
        if (($data['speak_policy'] ?? null) === 'everyone') {
            $room->present()->where('role', 'listener')->with('participant')->get()->each(function (ChatVoiceRoomMember $m) use ($room) {
                $m->update(['role' => 'speaker', 'hand_raised_at' => null]);
                $this->livekit->setCanPublish($room->room_name, $m->participant->key(), true);
            });
        }
        $this->changed($room);

        return $this->present($me, $room);
    }

    /** Out of the room after no heartbeat for a while (the sweep command). */
    public function sweep(): int
    {
        $stale = ChatVoiceRoomMember::query()->whereNull('left_at')->where(fn ($q) => $q->whereNull('seen_at')->orWhere('seen_at', '<', now()->subSeconds(self::STALE_SECONDS)))->get();
        foreach ($stale->groupBy('room_id') as $roomId => $rows) {
            ChatVoiceRoomMember::query()->whereIn('id', $rows->pluck('id'))->update(['left_at' => now(), 'hand_raised_at' => null]);
            if ($room = ChatVoiceRoom::query()->find($roomId)) {
                $this->changed($room);
            }
        }

        return $stale->count();
    }

    /** @return array<string, mixed> */
    public function present(Model $me, ChatVoiceRoom $room): array
    {
        $room->loadMissing('conversation');
        $mine = $this->conversations->participantOf($me, $room->conversation);
        $members = $room->present()->with('participant')->orderByRaw("CASE role WHEN 'host' THEN 0 WHEN 'speaker' THEN 1 ELSE 2 END")->orderBy('joined_at')->get();
        $this->directory->prime($me, $members->map(fn ($m) => [$m->participant->participant_type, $m->participant->participant_id]));
        $me_ = $members->firstWhere('participant_id', $mine->id);

        return [
            'id' => $room->uuid,
            'conversation_id' => $room->conversation->uuid,
            'title' => $room->title,
            'speak_policy' => $room->speak_policy,
            'count' => $members->count(),
            'members' => $members->map(function (ChatVoiceRoomMember $m) use ($me) {
                $p = $this->directory->profile($me, $m->participant->participant_type, $m->participant->participant_id);

                return [
                    'participant_id' => $m->participant_id,
                    'key' => $m->participant->key(),
                    'name' => $p['name'] ?? null,
                    'avatar' => $p['avatar'] ?? null,
                    'role' => $m->role,
                    'hand_raised' => $m->hand_raised_at !== null,
                    'joined_at' => $m->joined_at?->toIso8601String(),
                ];
            })->values()->all(),
            'me' => ['in_room' => $me_ !== null, 'role' => $me_?->role ?? $this->defaultRole($room, $mine), 'can_manage' => $mine->isAdmin()],
            'last_active_at' => $room->last_active_at?->toIso8601String(),
        ];
    }

    private function defaultRole(ChatVoiceRoom $room, ChatParticipant $p): string
    {
        return $p->isAdmin() ? 'host' : ($room->speak_policy === 'everyone' ? 'speaker' : 'listener');
    }

    private function memberOf(Model $me, ChatVoiceRoom $room): ?ChatVoiceRoomMember
    {
        $mine = $this->conversations->participantOf($me, $room->conversation);

        return $room->members()->where('participant_id', $mine->id)->first();
    }

    private function assertHost(Model $me, ChatVoiceRoom $room): void
    {
        $mine = $this->conversations->participantOf($me, $room->conversation, true);
        if (! $mine->isAdmin()) {
            throw ChatException::adminsOnly();
        }
    }

    /** Everyone in the chat sees who's in the room (the room bar, the room page). */
    private function changed(ChatVoiceRoom $room): void
    {
        $room->loadMissing('conversation');
        $count = $room->present()->count();
        $this->broadcaster->toParticipants($room->conversation->activeParticipants()->get(), 'chat.voice.updated', [
            'conversation_id' => $room->conversation->uuid,
            'room_id' => $room->uuid,
            'count' => $count,
            'speak_policy' => $room->speak_policy,
        ]);
    }
}
