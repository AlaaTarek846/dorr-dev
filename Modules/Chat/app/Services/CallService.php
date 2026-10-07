<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\CallParticipantStatus;
use Modules\Chat\Enums\CallStatus;
use Modules\Chat\Enums\CallType;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatCall;
use Modules\Chat\Models\ChatCallParticipant;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\LiveKitToken;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * Voice / video calls over LiveKit (decision 2026-09-27, docs/chat-plan.md §10.2).
 *
 * This class is the ringing state machine and the call log; the audio/video itself flows
 * between the apps and the LiveKit server. Each person who joins gets a short-lived token for
 * that one room — the LiveKit secret never leaves the server.
 *
 *   ringing ──accept──▶ ongoing ──last one leaves──▶ ended
 *      │  └─decline (everyone)──▶ declined
 *      ├─caller hangs up──▶ cancelled
 *      └─nobody answers in time (chat:expire-calls)──▶ missed
 *
 * When a call finishes, one `call` line is added to the chat ("Missed voice call", "Video call · 3:12").
 */
class CallService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ChatPrivacy $privacy,
        private readonly ChatBroadcaster $broadcaster,
        private readonly ChatPushNotifier $push,
        private readonly ParticipantDirectory $directory,
    ) {}

    /**
     * Start a call — or, in a group with a call already going, join that one instead.
     *
     * @return array{call: ChatCall, join: array<string, mixed>}
     */
    public function start(Model $me, ChatConversation $conversation, CallType $type): array
    {
        $settings = ChatSetting::current();

        if (! $settings->calls_enabled) {
            throw ChatException::callsDisabled();
        }
        // Switched off in my country (internet calls need a licence in some places).
        if (! $settings->callsAllowedFor($me)) {
            throw new ChatException('calls_unavailable_country', 403);
        }

        $mine = $this->conversations->participantOf($me, $conversation, true);

        if ($conversation->isChannel()) {
            throw new ChatException('channel_no_calls', 422);
        }

        if ($conversation->isSelf()) {
            throw new ChatException('self_no_calls', 422);
        }

        if ($conversation->status !== ConversationStatus::Accepted) {
            throw ChatException::requestPending();
        }

        $active = ChatCall::query()->where('conversation_id', $conversation->id)
            ->whereIn('status', [CallStatus::Ringing->value, CallStatus::Ongoing->value])->latest('id')->first();

        if ($active !== null) {
            if ($conversation->isGroup()) {
                return ['call' => $active, 'join' => $this->accept($me, $active)['join']];
            }
            throw ChatException::callBusy();
        }

        $others = $conversation->activeParticipants()->where('id', '!=', $mine->id)->get();

        if ($conversation->isGroup()) {
            // Members in a country where calls are off simply aren't rung.
            $others = $others->filter(fn (ChatParticipant $p) => $settings->callsAllowedFor($p->participant()))->values();

            if ($others->count() + 1 > $settings->max_call_participants) {
                throw ChatException::callTooManyParticipants($settings->max_call_participants);
            }
        } else {
            $peer = $others->first()?->participant() ?? throw ChatException::userNotFound();
            if (! $settings->callsAllowedFor($peer)) {
                throw new ChatException('calls_unavailable_peer_country', 403);
            }
            $this->privacy->assertCanCall($me, $peer);
        }

        // The caller's token is made inside the transaction: if LiveKit isn't configured the
        // call is rolled back, and since ringing is sent after commit, no phone ever rings.
        $join = null;

        $call = DB::transaction(function () use ($me, $mine, $conversation, $type, $others, &$join) {
            $call = ChatCall::query()->create([
                'conversation_id' => $conversation->id,
                'type' => $type,
                'status' => CallStatus::Ringing,
                'initiator_participant_id' => $mine->id,
            ]);

            ChatCallParticipant::query()->create(['call_id' => $call->id, 'participant_id' => $mine->id, 'status' => CallParticipantStatus::Joined, 'joined_at' => now()]);

            foreach ($others as $other) {
                ChatCallParticipant::query()->create(['call_id' => $call->id, 'participant_id' => $other->id, 'status' => CallParticipantStatus::Ringing]);
            }

            $join = $this->joinInfo($me, $mine, $call);

            $payload = $this->payload($me, $call) + ['caller' => $this->directory->profile($me, $mine->participant_type, $mine->participant_id)];
            $this->broadcaster->toParticipants($others, 'chat.call.ringing', $payload);
            $this->push->call((string) ($payload['caller']['account_name'] ?? ''), $type === CallType::Video, $others, [
                'event' => 'chat.call.ringing', 'call_id' => $call->uuid, 'conversation_uuid' => $conversation->uuid, 'call_type' => $type->value,
            ]);

            return $call;
        });

        return ['call' => $call, 'join' => $join];
    }

    /**
     * @return array{call: ChatCall, join: array<string, mixed>}
     */
    public function accept(Model $me, ChatCall $call): array
    {
        [$row, $callRow] = $this->rowsFor($me, $call);

        if ($call->status->isFinal()) {
            throw ChatException::callNotActive();
        }
        if (! ChatSetting::current()->callsAllowedFor($me)) {
            throw new ChatException('calls_unavailable_country', 403);
        }

        $join = DB::transaction(function () use ($me, $call, $row, $callRow) {
            $callRow->update(['status' => CallParticipantStatus::Joined, 'joined_at' => $callRow->joined_at ?? now(), 'left_at' => null]);

            if ($call->status === CallStatus::Ringing) {
                $call->update(['status' => CallStatus::Ongoing, 'answered_at' => now()]);
            }

            $this->broadcastState($call, 'chat.call.accepted', $row);

            return $this->joinInfo($me, $row, $call);
        });

        return ['call' => $call->refresh(), 'join' => $join];
    }

    public function decline(Model $me, ChatCall $call): ChatCall
    {
        [$row, $callRow] = $this->rowsFor($me, $call);

        if ($call->status->isFinal()) {
            return $call;
        }

        DB::transaction(function () use ($call, $row, $callRow) {
            $callRow->update(['status' => CallParticipantStatus::Declined]);

            $stillRinging = $call->callParticipants()->where('status', CallParticipantStatus::Ringing->value)->exists();
            $anyoneJoined = $call->callParticipants()->where('participant_id', '!=', $call->initiator_participant_id)->where('status', CallParticipantStatus::Joined->value)->exists();

            // A direct call ends as soon as the other person declines; a group call only once nobody is left to answer.
            if ($call->status === CallStatus::Ringing && ! $stillRinging && ! $anyoneJoined) {
                $this->finish($call, CallStatus::Declined);
            } else {
                $this->broadcastState($call, 'chat.call.declined', $row);
            }
        });

        return $call->refresh();
    }

    /**
     * Hang up. The caller hanging up before anyone answered cancels the call (a missed call
     * for the others); the last person to leave an ongoing call ends it.
     */
    public function leave(Model $me, ChatCall $call): ChatCall
    {
        [$row, $callRow] = $this->rowsFor($me, $call);

        if ($call->status->isFinal()) {
            return $call;
        }

        DB::transaction(function () use ($call, $row, $callRow) {
            $callRow->update(['status' => CallParticipantStatus::Left, 'left_at' => now()]);

            if ($call->status === CallStatus::Ringing) {
                if ($row->id === $call->initiator_participant_id) {
                    // The caller's app gives up once it rang out: that's a missed call, not a
                    // cancelled one (and it no longer waits for chat:expire-calls to run).
                    $rangOut = $call->created_at->lte(now()->subSeconds((int) config('chat.call_ring_timeout_seconds', 45)));
                    $this->finish($call, $rangOut ? CallStatus::Missed : CallStatus::Cancelled);
                }

                return;
            }

            $stillIn = $call->callParticipants()->where('status', CallParticipantStatus::Joined->value)->count();

            // A direct call is over when either side hangs up.
            if ($stillIn === 0 || ! $call->conversation->isGroup()) {
                $this->finish($call, CallStatus::Ended);
            } else {
                $this->broadcastState($call, 'chat.call.left', $row);
            }
        });

        return $call->refresh();
    }

    /**
     * A fresh token to (re)join an ongoing call — e.g. after the app was restarted mid-call.
     *
     * @return array<string, mixed>
     */
    public function token(Model $me, ChatCall $call): array
    {
        [$row] = $this->rowsFor($me, $call);

        if ($call->status->isFinal()) {
            throw ChatException::callNotActive();
        }

        return $this->joinInfo($me, $row, $call);
    }

    /**
     * Ringing calls nobody answered in time become missed (scheduled every minute).
     */
    public function expireUnanswered(): int
    {
        $timeout = (int) config('chat.call_ring_timeout_seconds', 45);
        $count = 0;

        ChatCall::query()->where('status', CallStatus::Ringing->value)
            ->where('created_at', '<=', now()->subSeconds($timeout))
            ->each(function (ChatCall $call) use (&$count) {
                DB::transaction(fn () => $this->finish($call, CallStatus::Missed));
                $count++;
            });

        return $count;
    }

    /**
     * My call log (the "Calls" tab), newest first.
     */
    public function history(Model $me, int $perPage = 30): LengthAwarePaginator
    {
        $rows = ChatParticipant::query()->of($me)->pluck('id');

        return ChatCall::query()
            ->whereIn('id', ChatCallParticipant::query()->whereIn('participant_id', $rows)->select('call_id'))
            ->with(['conversation.group', 'conversation.participants', 'callParticipants'])
            ->latest('id')->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Model $viewer, ChatCall $call): array
    {
        $call->loadMissing(['conversation.group', 'callParticipants.participant']);
        $myRows = ChatParticipant::query()->of($viewer)->where('conversation_id', $call->conversation_id)->pluck('id')->all();
        $initiator = $call->callParticipants->firstWhere('participant_id', $call->initiator_participant_id)?->participant;
        $this->directory->prime($viewer, $call->callParticipants->map(fn ($cp) => [$cp->participant?->participant_type, $cp->participant?->participant_id]));

        return [
            'id' => $call->uuid,
            'conversation_id' => $call->conversation->uuid,
            'conversation_type' => $call->conversation->type->value,
            'group_name' => $call->conversation->group?->name,
            'type' => $call->type->value,
            'status' => $call->status->value,
            'is_outgoing' => in_array($call->initiator_participant_id, $myRows, true),
            'initiator' => $initiator ? $this->directory->profile($viewer, $initiator->participant_type, $initiator->participant_id) : null,
            'participants' => $call->callParticipants->map(fn (ChatCallParticipant $cp) => [
                'profile' => $cp->participant ? $this->directory->profile($viewer, $cp->participant->participant_type, $cp->participant->participant_id) : null,
                'status' => $cp->status->value,
            ])->values()->all(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $call->durationSeconds(),
            'created_at' => $call->created_at?->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array{0: ChatParticipant, 1: ChatCallParticipant}
     */
    private function rowsFor(Model $me, ChatCall $call): array
    {
        $row = $this->conversations->participantOf($me, $call->conversation);
        $callRow = ChatCallParticipant::query()->where('call_id', $call->id)->where('participant_id', $row->id)->first();

        if ($callRow === null) {
            // Joined the group after the call started: may still hop in.
            if (! $call->conversation->isGroup() || $call->status->isFinal()) {
                throw ChatException::notParticipant();
            }
            $callRow = ChatCallParticipant::query()->create(['call_id' => $call->id, 'participant_id' => $row->id, 'status' => CallParticipantStatus::Ringing]);
        }

        return [$row, $callRow];
    }

    /**
     * @return array<string, mixed>
     */
    private function joinInfo(Model $me, ChatParticipant $row, ChatCall $call): array
    {
        $profile = $this->directory->profile($me, $row->participant_type, $row->participant_id);

        return [
            'url' => LiveKitToken::url() ?? throw ChatException::callsNotConfigured(),
            'room' => $call->room_name,
            'token' => LiveKitToken::issue($call->room_name, $row->key(), (string) ($profile['account_name'] ?? ''), [
                'participant' => $row->key(),
                'avatar' => $profile['avatar'] ?? null,
            ]),
        ];
    }

    private function finish(ChatCall $call, CallStatus $status): void
    {
        $call->update(['status' => $status, 'ended_at' => now()]);

        $call->callParticipants()->where('status', CallParticipantStatus::Ringing->value)
            ->update(['status' => CallParticipantStatus::Missed->value]);
        $call->callParticipants()->where('status', CallParticipantStatus::Joined->value)
            ->update(['status' => CallParticipantStatus::Left->value, 'left_at' => now()]);

        $conversation = $call->conversation;
        $initiator = $call->initiator;

        // One line in the chat, "sent" by the caller, so it sits in the conversation like any message.
        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => $initiator->participant_type,
            'sender_id' => $initiator->participant_id,
            'type' => MessageType::Call,
            'meta' => [
                'call_id' => $call->uuid,
                'call_type' => $call->type->value,
                'status' => $status->value,
                'duration_seconds' => $call->durationSeconds(),
            ],
        ]);
        $this->messages->afterNewMessage($conversation, $message, $initiator, []);

        $this->broadcastState($call->refresh(), 'chat.call.ended', null);

        if (in_array($status, [CallStatus::Missed, CallStatus::Cancelled], true)) {
            $missed = ChatParticipant::query()->whereIn('id', $call->callParticipants()->where('status', CallParticipantStatus::Missed->value)->pluck('participant_id'))->get();
            $caller = $initiator->participant();
            $this->push->missedCall((string) ($caller->name ?? ''), $call->type === CallType::Video, $missed, [
                'event' => 'chat.call.missed', 'call_id' => $call->uuid, 'conversation_uuid' => $conversation->uuid,
            ]);
        }
    }

    private function broadcastState(ChatCall $call, string $event, ?ChatParticipant $actor): void
    {
        $call->load('callParticipants');

        $this->broadcaster->toParticipants($call->conversation->activeParticipants()->get(), $event, [
            'call_id' => $call->uuid,
            'conversation_id' => $call->conversation->uuid,
            'status' => $call->status->value,
            'participant' => $actor?->key(),
            'duration_seconds' => $call->durationSeconds(),
        ]);
    }
}
