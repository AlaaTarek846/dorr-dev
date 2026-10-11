<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\CallParticipantStatus;
use Modules\Chat\Enums\CallStatus;
use Modules\Chat\Enums\CallType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatCall;
use Modules\Chat\Models\ChatCallParticipant;
use Modules\Chat\Models\ChatCallRecording;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\LiveKitEgress;
use Modules\Chat\Support\LiveKitToken;
use Modules\Chat\Support\RecordingStorage;

/**
 * Call recording (spec 92, safety rule 1).
 *
 *   pending ──everyone in the call said yes──▶ recording ──stop / call ends──▶ processing ──saved──▶ ready ──retention──▶ deleted
 *      ├─anyone says no──▶ declined
 *      ├─not everyone answered in time──▶ expired
 *      └─taken back / the call ended──▶ cancelled                         (LiveKit failed ──▶ failed)
 *
 * Nobody is recorded without saying yes: recording starts only when every person in the call has
 * agreed, and someone joining a call that is asking or recording agrees before they're let in
 * (CallService asks assertMayJoin). Anyone in the call can stop it. LiveKit Egress records the
 * whole room — cameras in a grid for a video call — straight into our bucket; only the people who
 * agreed can play it, through a link signed for a few minutes, and the file is deleted after the
 * admin's retention days.
 */
class CallRecordingService
{
    /** Saving a file that hasn't arrived after this long is given up. */
    private const PROCESSING_HOURS = 6;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ChatBroadcaster $broadcaster,
        private readonly LiveKitEgress $egress,
        private readonly RecordingStorage $storage,
    ) {}

    /** The "record" button shows only when a recording can really be made. */
    public function available(): bool
    {
        return ChatSetting::current()->callRecordingEnabled() && $this->storage->configured() && LiveKitToken::url() !== null;
    }

    /** Ask everyone in the call to agree to a recording — my asking is my yes. */
    public function request(Model $me, ChatCall $call): ChatCallRecording
    {
        if (! $this->available()) {
            throw new ChatException('recording_unavailable', 422);
        }
        $mine = $this->joinedRow($me, $call);

        $recording = DB::transaction(function () use ($call, $mine) {
            // One at a time: two people asking together get the same request.
            ChatCall::query()->whereKey($call->id)->lockForUpdate()->first();
            if (($open = $this->open($call)) !== null) {
                return $open;
            }
            $recording = ChatCallRecording::query()->create([
                'call_id' => $call->id,
                'conversation_id' => $call->conversation_id,
                'requested_by_participant_id' => $mine->id,
                'status' => 'pending',
                'consent_until' => now()->addSeconds((int) config('chat.recordings.consent_seconds', 30)),
            ]);
            $this->decide($recording, $mine, true);

            return $recording;
        });

        return $this->evaluate($recording);
    }

    /** My answer to the request: yes — or no, and then nothing is recorded. */
    public function answer(Model $me, ChatCall $call, bool $accept): ChatCallRecording
    {
        $mine = $this->joinedRow($me, $call);
        $recording = $this->open($call);
        if ($recording?->status !== 'pending') {
            throw new ChatException('recording_not_asking', 409);
        }
        $this->decide($recording, $mine, $accept);
        if (! $accept) {
            $this->close($recording, 'declined');

            return $recording->refresh();
        }

        return $this->evaluate($recording);
    }

    /** Stop the recording, or take the request back. Anyone in the call can. */
    public function stop(Model $me, ChatCall $call): ChatCallRecording
    {
        $mine = $this->joinedRow($me, $call);
        $recording = $this->open($call) ?? throw new ChatException('recording_not_running', 409);
        if ($recording->status === 'pending') {
            $this->close($recording, 'cancelled');
        } else {
            $this->stopEgress($recording, $mine);
        }

        return $recording->refresh();
    }

    /**
     * Answering, hopping into a group call or re-joining while the call asks or records: only
     * with a yes, which counts as agreeing to the recording.
     */
    public function assertMayJoin(ChatCall $call, ChatParticipant $row, bool $consent): void
    {
        $recording = $this->open($call);
        if ($recording === null || $this->agreed($recording, $row)) {
            return;
        }
        if (! $consent) {
            throw new ChatException('recording_consent_required', 409, [], ['recording' => $this->state($recording)]);
        }
        $this->decide($recording, $row, true);
    }

    /** Someone left: the ones still in may all have said yes now. */
    public function participantLeft(ChatCall $call): void
    {
        $recording = $this->open($call);
        if ($recording?->status === 'pending') {
            $this->evaluate($recording);
        }
    }

    /** The call ended: a request is dropped, a recording stops (LiveKit would end it with the room anyway). */
    public function callEnded(ChatCall $call): void
    {
        $recording = $this->open($call);
        if ($recording?->status === 'pending') {
            $this->close($recording, 'cancelled');
        } elseif ($recording !== null) {
            $this->stopEgress($recording, null);
        }
    }

    /** For the line the call leaves in the chat. */
    public function wasRecorded(ChatCall $call): bool
    {
        return $call->recordings()->whereIn('status', [...ChatCallRecording::KEPT, 'deleted'])->exists();
    }

    /**
     * Every minute: requests nobody finished answering, files LiveKit has saved since, and files
     * past their retention.
     */
    public function sync(): int
    {
        $count = 0;
        // By id: a row whose status changes on the way doesn't shift the next page.
        ChatCallRecording::query()->where('status', 'pending')->where('consent_until', '<=', now())->lazyById()
            ->each(function (ChatCallRecording $r) use (&$count) {
                $count += (int) $this->close($r, 'expired');
            });
        ChatCallRecording::query()->whereIn('status', ['recording', 'processing'])->lazyById()
            ->each(function (ChatCallRecording $r) use (&$count) {
                $count += (int) $this->refresh($r);
            });
        ChatCallRecording::query()->where('status', 'ready')->where('expires_at', '<=', now())->lazyById()
            ->each(function (ChatCallRecording $r) use (&$count) {
                $count += (int) $this->purge($r);
            });

        return $count;
    }

    /**
     * The recordings of a call I agreed to, newest last, each with a link to play it once saved.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(Model $me, ChatCall $call): array
    {
        $mine = $this->conversations->participantOf($me, $call->conversation);
        $recordings = $call->recordings()->whereIn('status', ChatCallRecording::KEPT)
            ->whereIn('id', DB::table('chat_call_recording_consents')->where('participant_id', $mine->id)->where('decision', 'accepted')->select('recording_id'))
            ->oldest('id')->get();

        return $recordings->map(function (ChatCallRecording $r) {
            // Saved since the last sync? Ask LiveKit now rather than in a minute.
            if ($r->status === 'processing') {
                $this->refresh($r);
            }

            return $this->present($r->refresh(), withUrl: true);
        })->values()->all();
    }

    /**
     * What the call screen shows: who asked, who said yes or no, who still has to answer.
     *
     * @return array<string, mixed>
     */
    public function state(ChatCallRecording $recording): array
    {
        $consents = DB::table('chat_call_recording_consents')->where('recording_id', $recording->id)->get(['participant_id', 'decision']);
        $waiting = $recording->status === 'pending' ? $this->waitingFor($recording) : [];
        $ids = array_merge($consents->pluck('participant_id')->all(), $waiting, [$recording->requested_by_participant_id]);
        $keys = ChatParticipant::query()->whereIn('id', array_filter($ids))->get()->mapWithKeys(fn (ChatParticipant $p) => [$p->id => $p->key()]);
        $keysOf = fn (iterable $ids) => collect($ids)->map(fn ($id) => $keys[$id] ?? null)->filter()->values()->all();

        return [
            'id' => $recording->uuid,
            'call_id' => $recording->call?->uuid,
            'status' => $recording->status,
            'requested_by' => $keys[$recording->requested_by_participant_id] ?? null,
            'agreed' => $keysOf($consents->where('decision', 'accepted')->pluck('participant_id')),
            'declined' => $keysOf($consents->where('decision', 'declined')->pluck('participant_id')),
            'waiting_for' => $keysOf($waiting),
            'consent_until' => $recording->consent_until?->toIso8601String(),
            'started_at' => $recording->started_at?->toIso8601String(),
            'stopped_at' => $recording->stopped_at?->toIso8601String(),
        ];
    }

    /**
     * For the call's payload: may it be recorded, what's asking / recording now, and how many
     * recordings this viewer can play.
     *
     * @param  list<int>  $myRows
     * @return array{recording_available: bool, recording: array<string, mixed>|null, recordings_count: int}
     */
    public function summary(ChatCall $call, array $myRows): array
    {
        $recordings = $call->relationLoaded('recordings') ? $call->recordings : $call->recordings()->get();
        $open = $recordings->first(fn (ChatCallRecording $r) => $r->isOpen());
        $kept = $recordings->whereIn('status', ChatCallRecording::KEPT)->pluck('id');
        $count = $kept->isEmpty() || $myRows === [] ? 0 : DB::table('chat_call_recording_consents')
            ->whereIn('recording_id', $kept)->whereIn('participant_id', $myRows)->where('decision', 'accepted')->count();

        return [
            'recording_available' => ! $call->status->isFinal() && $this->available(),
            'recording' => $open ? $this->state($open) : null,
            'recordings_count' => $count,
        ];
    }

    /** @return array<string, mixed> */
    public function present(ChatCallRecording $recording, bool $withUrl = false): array
    {
        $recording->loadMissing('call');
        $seconds = $recording->duration_seconds
            ?? ($recording->started_at && $recording->stopped_at ? (int) $recording->started_at->diffInSeconds($recording->stopped_at) : null);

        return [
            'id' => $recording->uuid,
            'call_id' => $recording->call?->uuid,
            'type' => $recording->call?->type->value,
            'status' => $recording->status,
            'duration_seconds' => $seconds,
            'size_bytes' => $recording->size_bytes,
            'started_at' => $recording->started_at?->toIso8601String(),
            'stopped_at' => $recording->stopped_at?->toIso8601String(),
            'expires_at' => $recording->expires_at?->toIso8601String(),
            'url' => $withUrl && $recording->status === 'ready' && $recording->file_path ? $this->storage->url($recording->file_path) : null,
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function open(ChatCall $call): ?ChatCallRecording
    {
        return ChatCallRecording::query()->where('call_id', $call->id)->whereIn('status', ChatCallRecording::OPEN)->latest('id')->first();
    }

    /** I'm in this call right now (asking, answering and stopping are for the people in it). */
    private function joinedRow(Model $me, ChatCall $call): ChatParticipant
    {
        $mine = $this->conversations->participantOf($me, $call->conversation);
        $inCall = ChatCallParticipant::query()->where('call_id', $call->id)->where('participant_id', $mine->id)
            ->where('status', CallParticipantStatus::Joined->value)->exists();
        if ($call->status !== CallStatus::Ongoing || ! $inCall) {
            throw new ChatException('recording_not_in_call', 409);
        }

        return $mine;
    }

    private function decide(ChatCallRecording $recording, ChatParticipant $row, bool $accept): void
    {
        DB::table('chat_call_recording_consents')->updateOrInsert(
            ['recording_id' => $recording->id, 'participant_id' => $row->id],
            ['decision' => $accept ? 'accepted' : 'declined', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function agreed(ChatCallRecording $recording, ChatParticipant $row): bool
    {
        return DB::table('chat_call_recording_consents')->where('recording_id', $recording->id)
            ->where('participant_id', $row->id)->where('decision', 'accepted')->exists();
    }

    /**
     * The people in the call right now who haven't said yes.
     *
     * @return list<int>
     */
    private function waitingFor(ChatCallRecording $recording): array
    {
        $joined = ChatCallParticipant::query()->where('call_id', $recording->call_id)
            ->where('status', CallParticipantStatus::Joined->value)->pluck('participant_id')->all();
        $agreed = DB::table('chat_call_recording_consents')->where('recording_id', $recording->id)
            ->where('decision', 'accepted')->pluck('participant_id')->all();

        return array_values(array_diff($joined, $agreed));
    }

    /** Everyone in the call said yes → record; the time to answer ran out → drop it. */
    private function evaluate(ChatCallRecording $recording): ChatCallRecording
    {
        if ($recording->status !== 'pending') {
            return $recording;
        }
        if ($recording->call->status !== CallStatus::Ongoing) {
            $this->close($recording, 'cancelled');
        } elseif ($this->waitingFor($recording) === []) {
            $this->startEgress($recording);
        } elseif ($recording->consent_until?->isPast()) {
            $this->close($recording, 'expired');
        } else {
            $this->tell($recording);
        }

        return $recording->refresh();
    }

    private function startEgress(ChatCallRecording $recording): void
    {
        // Taken by one request only: two last yeses at the same moment start one recording.
        $claimed = ChatCallRecording::query()->whereKey($recording->id)->where('status', 'pending')
            ->update(['status' => 'recording', 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $recording->refresh();
        $call = $recording->call;
        $key = $this->storage->keyFor($call->uuid, $recording->uuid);
        $info = $this->egress->startRoomComposite($call->room_name, $call->type === CallType::Audio, $key, $this->storage->egressUpload());
        $id = $info !== null ? LiveKitEgress::id($info) : null;
        $recording->update($id !== null
            ? ['egress_id' => $id, 'file_path' => $key]
            : ['status' => 'failed', 'error' => 'egress_start', 'stopped_at' => now()]);
        $this->tell($recording);
    }

    private function stopEgress(ChatCallRecording $recording, ?ChatParticipant $by): void
    {
        $stopped = ChatCallRecording::query()->whereKey($recording->id)->where('status', 'recording')
            ->update(['status' => 'processing', 'stopped_at' => now(), 'stopped_by_participant_id' => $by?->id, 'updated_at' => now()]);
        if ($stopped === 0) {
            return;
        }
        $recording->refresh();
        if ($recording->egress_id !== null) {
            $this->egress->stop($recording->egress_id);
        }
        $this->tell($recording);
    }

    /** A request that won't record: said no, ran out of time, taken back. */
    private function close(ChatCallRecording $recording, string $status): bool
    {
        $closed = ChatCallRecording::query()->whereKey($recording->id)->where('status', 'pending')
            ->update(['status' => $status, 'updated_at' => now()]);
        if ($closed > 0) {
            $this->tell($recording->refresh());
        }

        return $closed > 0;
    }

    /** What LiveKit says about a recording: still going, saved, or failed. */
    private function refresh(ChatCallRecording $recording): bool
    {
        if ($recording->egress_id === null) {
            // LiveKit never answered the start.
            if ($recording->updated_at?->lte(now()->subHours(self::PROCESSING_HOURS))) {
                $recording->update(['status' => 'failed', 'error' => 'egress_lost', 'stopped_at' => $recording->stopped_at ?? now()]);
                $this->tell($recording);

                return true;
            }

            return false;
        }
        $info = $this->egress->info($recording->egress_id);
        if ($info === null) {
            return false;
        }
        $status = LiveKitEgress::status($info);
        if (in_array($status, ['EGRESS_STARTING', 'EGRESS_ACTIVE', 'EGRESS_ENDING'], true)) {
            // Still recording though it was stopped (before LiveKit had started it) or its call is over.
            if ($status !== 'EGRESS_ENDING' && $recording->status === 'processing') {
                $this->egress->stop($recording->egress_id);
            } elseif ($status !== 'EGRESS_ENDING' && $recording->call?->status->isFinal()) {
                $this->stopEgress($recording, null);
            }

            return false;
        }
        $file = LiveKitEgress::file($info);
        if ($status === 'EGRESS_COMPLETE' || ($status === 'EGRESS_LIMIT_REACHED' && $file !== null)) {
            $recording->update([
                'status' => 'ready',
                'file_path' => $file['key'] ?? $recording->file_path,
                'size_bytes' => $file['size'] ?? null,
                'duration_seconds' => $file['seconds'] ?? null,
                'stopped_at' => $recording->stopped_at ?? now(),
                'expires_at' => now()->addDays(ChatSetting::current()->callRecordingRetentionDays()),
            ]);
        } else {
            $recording->update(['status' => 'failed', 'error' => mb_substr((string) ($info['error'] ?? $status), 0, 250), 'stopped_at' => $recording->stopped_at ?? now()]);
        }
        $this->tell($recording);

        return true;
    }

    /** Past its retention: the file goes (kept as "deleted" so the call still shows it was recorded). */
    private function purge(ChatCallRecording $recording): bool
    {
        if ($recording->file_path !== null && ! $this->storage->delete($recording->file_path)) {
            return false;
        }
        $recording->update(['status' => 'deleted', 'file_path' => null]);

        return true;
    }

    /** Everyone the call rang (in it, or still ringing) sees the recording's state. */
    private function tell(ChatCallRecording $recording): void
    {
        $call = $recording->call;
        if ($call === null) {
            return;
        }
        $rows = ChatParticipant::query()->whereIn('id', $call->callParticipants()->pluck('participant_id'))->get();
        $this->broadcaster->toParticipants($rows, 'chat.call.recording', ['call_id' => $call->uuid, 'recording' => $this->state($recording)]);
    }
}
