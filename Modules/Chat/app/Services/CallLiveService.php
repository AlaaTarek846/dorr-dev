<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\CallParticipantStatus;
use Modules\Chat\Enums\CallStatus;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatCall;
use Modules\Chat\Models\ChatCallLive;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\LiveKitToken;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * A call turned into a live broadcast (spec 93). Someone in the call (who joined it) starts it —
 * in a group only its admins — choosing who may watch: the chat's members, or anyone in DORR
 * with the link. Viewers get a LiveKit token that can't publish and keeps them hidden, so the
 * call's people see only how many watch. It ends with the call. Its card (`live_card`) can be
 * shared into any chat I'm in.
 */
class CallLiveService
{
    /** A viewer without a heartbeat for this long isn't counted. */
    public const VIEWER_SECONDS = 60;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ParticipantDirectory $directory,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    public function start(Model $me, ChatCall $call, ?string $title, string $audience): ChatCallLive
    {
        $conversation = $call->conversation;
        $mine = $this->conversations->participantOf($me, $conversation, true);
        $inCall = $call->callParticipants()->where('participant_id', $mine->id)->where('status', CallParticipantStatus::Joined->value)->exists();
        if ($call->status !== CallStatus::Ongoing || ! $inCall) {
            throw new ChatException('live_not_in_call', 409);
        }
        if ($conversation->isGroup() && ! $mine->isAdmin()) {
            throw ChatException::adminsOnly();
        }
        if (($existing = ChatCallLive::query()->where('call_id', $call->id)->first()) !== null) {
            return $existing->isLive() ? $existing : throw new ChatException('live_ended', 409);
        }
        $live = ChatCallLive::query()->create([
            'uuid' => (string) Str::uuid(),
            'call_id' => $call->id,
            'conversation_id' => $conversation->id,
            'started_by_participant_id' => $mine->id,
            'title' => $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 120) : null,
            'audience' => $audience,
            'token' => Str::random(32),
            'status' => 'live',
            'started_at' => now(),
        ]);
        $this->tellCall($call, $live);

        return $live;
    }

    public function end(Model $me, ChatCallLive $live): ChatCallLive
    {
        $mine = $this->conversations->participantOf($me, $live->conversation, true);
        if ($mine->id !== $live->started_by_participant_id && ! $mine->isAdmin()) {
            throw ChatException::adminsOnly();
        }
        $this->close($live);

        return $live->refresh();
    }

    /** The call ended: so does its broadcast. */
    public function callEnded(ChatCall $call): void
    {
        $live = ChatCallLive::query()->where('call_id', $call->id)->where('status', 'live')->first();
        if ($live !== null) {
            $this->close($live);
        }
    }

    /** @return array{live: array<string, mixed>, join: array{url: string, room: string, token: string}|null} */
    public function watch(Model $me, string $token): array
    {
        $live = $this->byToken($token);
        $this->assertMayWatch($me, $live);
        if (! $live->isLive()) {
            return ['live' => $this->present($me, $live), 'join' => null];
        }
        $type = ParticipantType::aliasFor($me);
        DB::table('chat_call_live_viewers')->updateOrInsert(
            ['live_id' => $live->id, 'viewer_type' => $type, 'viewer_id' => $me->getKey()],
            ['seen_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );
        $viewers = $this->viewers($live);
        if ($viewers > $live->viewers_peak) {
            $live->update(['viewers_peak' => $viewers]);
        }
        $this->tellCall($live->call, $live);
        $url = LiveKitToken::url() ?? throw ChatException::callsNotConfigured();

        return [
            'live' => $this->present($me, $live),
            'join' => [
                'url' => $url,
                'room' => $live->call->room_name,
                // A viewer: listens and watches, can't speak or send, and isn't listed in the call.
                'token' => LiveKitToken::issue($live->call->room_name, 'viewer:'.$type.':'.$me->getKey(), (string) ($me->name ?? ''), ['viewer' => true],
                    ['canPublish' => false, 'canPublishData' => false, 'hidden' => true]),
            ],
        ];
    }

    /** Still watching (every 30 s). */
    public function ping(Model $me, string $token): array
    {
        $live = $this->byToken($token);
        DB::table('chat_call_live_viewers')->where('live_id', $live->id)->where('viewer_type', ParticipantType::aliasFor($me))->where('viewer_id', $me->getKey())->update(['seen_at' => now()]);

        return $this->present($me, $live);
    }

    /** @return array<string, mixed> */
    public function show(Model $me, string $token): array
    {
        $live = $this->byToken($token);
        $this->assertMayWatch($me, $live);

        return $this->present($me, $live);
    }

    /**
     * Its card into chats I'm in.
     *
     * @param  list<string>  $conversationUuids
     */
    public function share(Model $me, string $token, array $conversationUuids): int
    {
        $live = $this->byToken($token);
        $this->assertMayWatch($me, $live);
        if (! $live->isLive()) {
            throw new ChatException('live_ended', 409);
        }
        $sent = 0;
        foreach (ChatConversation::query()->whereIn('uuid', $conversationUuids)->get() as $target) {
            // A members-only broadcast goes only into its own chat.
            if ($live->audience === 'members' && $target->id !== $live->conversation_id) {
                continue;
            }
            $this->messages->send($me, $target, ['type' => MessageType::LiveCard->value, 'body' => $live->title, 'meta_raw' => $this->cardMeta($live)]);
            $sent++;
        }

        return $sent;
    }

    /** @return array<string, mixed> */
    public function present(Model $me, ChatCallLive $live): array
    {
        $live->loadMissing(['conversation.group', 'startedBy']);
        $host = $live->startedBy ? $this->directory->profile($me, $live->startedBy->participant_type, $live->startedBy->participant_id) : null;

        return [
            'id' => $live->uuid,
            'token' => $live->token,
            'link' => 'dorr://live/'.$live->token,
            'title' => $live->title ?? $live->conversation?->group?->name,
            'audience' => $live->audience,
            'status' => $live->status,
            'host' => $host ? ['name' => $host['account_name'] ?? $host['name'] ?? null, 'avatar' => $host['avatar'] ?? null] : null,
            'viewers' => $this->viewers($live),
            'viewers_peak' => $live->viewers_peak,
            'started_at' => $live->started_at?->toIso8601String(),
            'ended_at' => $live->ended_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function cardMeta(ChatCallLive $live): array
    {
        $live->loadMissing(['conversation.group', 'startedBy']);
        $host = $live->startedBy?->participant();

        return ['live' => ['token' => $live->token, 'title' => $live->title ?? $live->conversation?->group?->name, 'host' => $host?->name ?? null, 'audience' => $live->audience]];
    }

    private function viewers(ChatCallLive $live): int
    {
        return DB::table('chat_call_live_viewers')->where('live_id', $live->id)->where('seen_at', '>=', now()->subSeconds(self::VIEWER_SECONDS))->count();
    }

    private function byToken(string $token): ChatCallLive
    {
        return ChatCallLive::query()->where('token', $token)->with(['call', 'conversation'])->first() ?? throw new ChatException('live_not_found', 404);
    }

    private function assertMayWatch(Model $me, ChatCallLive $live): void
    {
        if ($live->audience === 'members') {
            $mine = ChatParticipant::query()->of($me)->where('conversation_id', $live->conversation_id)->whereNull('left_at')->first();
            if ($mine === null) {
                throw new ChatException('live_members_only', 403);
            }
        }
    }

    private function close(ChatCallLive $live): void
    {
        if (! $live->isLive()) {
            return;
        }
        $live->update(['status' => 'ended', 'ended_at' => now()]);
        $this->tellCall($live->call, $live);
    }

    /** The people in the call see it's live, and how many watch. */
    private function tellCall(?ChatCall $call, ChatCallLive $live): void
    {
        if ($call === null) {
            return;
        }
        $rows = ChatParticipant::query()->whereIn('id', $call->callParticipants()->pluck('participant_id'))->get();
        $this->broadcaster->toParticipants($rows, 'chat.call.live', [
            'call_id' => $call->uuid,
            'live' => ['id' => $live->uuid, 'token' => $live->token, 'status' => $live->status, 'viewers' => $this->viewers($live), 'audience' => $live->audience],
        ]);
    }
}
