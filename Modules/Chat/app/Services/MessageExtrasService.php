<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageUserState;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPollVote;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * The message types that keep changing after they're sent: polls (votes), view-once media
 * (opened once, then gone for that person) and live location (moves until it runs out).
 */
class MessageExtrasService
{
    /** Live location can be shared for 15 minutes, 1 hour or 8 hours (like WhatsApp). */
    public const LIVE_DURATIONS = [900, 3600, 28800];

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    // ---------------------------------------------------------------- polls

    /**
     * The poll's structure as it's saved: numbered options (ids never change) and whether several
     * answers are allowed.
     *
     * @param  list<string>  $options
     * @return array{options: list<array{id: int, text: string}>, multiple: bool}
     */
    public static function pollMeta(array $options, bool $multiple): array
    {
        $clean = array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), $options), fn ($o) => $o !== '')));

        if (count($clean) < 2 || count($clean) > 12) {
            throw new ChatException('poll_invalid', 422);
        }

        return [
            'options' => array_map(fn (string $text, int $i) => ['id' => $i + 1, 'text' => mb_substr($text, 0, 100)], $clean, array_keys($clean)),
            'multiple' => $multiple,
        ];
    }

    /**
     * Replace my answer. `[]` takes my vote back; one option only unless the poll allows many.
     *
     * @param  list<int>  $optionIds
     */
    public function vote(Model $me, ChatMessage $message, array $optionIds): ChatMessage
    {
        $participant = $this->conversations->participantOf($me, $message->conversation, true);
        $this->assertPoll($message);

        $valid = collect((array) data_get($message->meta, 'options'))->pluck('id')->map(fn ($id) => (int) $id);
        $picked = collect($optionIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($picked->diff($valid)->isNotEmpty() || (! data_get($message->meta, 'multiple') && $picked->count() > 1)) {
            throw new ChatException('poll_invalid_vote', 422);
        }

        DB::transaction(function () use ($message, $participant, $picked) {
            ChatPollVote::query()->where('message_id', $message->id)->where('participant_id', $participant->id)->delete();
            foreach ($picked as $optionId) {
                ChatPollVote::query()->create(['message_id' => $message->id, 'participant_id' => $participant->id, 'option_id' => $optionId]);
            }
        });

        $summary = self::pollSummary($message->id);
        $this->broadcaster->toParticipants($message->conversation->activeParticipants()->get(), 'chat.poll.updated', [
            'conversation_id' => $message->conversation->uuid,
            'message_id' => $message->uuid,
            'participant' => $participant->key(),
            'option_ids' => $picked->all(),
            'counts' => $summary['counts'],
            'voters' => $summary['voters'],
        ]);

        return $message;
    }

    /**
     * Who picked what (not anonymous, like WhatsApp).
     *
     * @return list<array{id: int, text: string, voters: list<array<string, mixed>|null>}>
     */
    public function voters(Model $me, ChatMessage $message): array
    {
        $this->conversations->participantOf($me, $message->conversation);
        $this->assertPoll($message);

        $votes = ChatPollVote::query()->where('message_id', $message->id)->orderBy('created_at')->get();
        $rows = ChatParticipant::query()->whereIn('id', $votes->pluck('participant_id')->unique())->get()->keyBy('id');
        $directory = app(ParticipantDirectory::class);
        $directory->prime($me, $rows->map(fn ($p) => [$p->participant_type, $p->participant_id])->values());

        return collect((array) data_get($message->meta, 'options'))->map(fn ($option) => [
            'id' => (int) $option['id'],
            'text' => (string) $option['text'],
            'voters' => $votes->where('option_id', (int) $option['id'])->map(function (ChatPollVote $v) use ($rows, $directory, $me) {
                $p = $rows->get($v->participant_id);

                return $p ? $directory->profile($me, $p->participant_type, $p->participant_id) : null;
            })->filter()->values()->all(),
        ])->all();
    }

    /**
     * @return array{counts: array<int, int>, voters: int}
     */
    public static function pollSummary(int $messageId): array
    {
        $votes = ChatPollVote::query()->where('message_id', $messageId)->get(['participant_id', 'option_id']);

        return [
            'counts' => $votes->countBy('option_id')->map(fn ($n) => (int) $n)->all(),
            'voters' => $votes->pluck('participant_id')->unique()->count(),
        ];
    }

    private function assertPoll(ChatMessage $message): void
    {
        if ($message->type !== MessageType::Poll || $message->isGone()) {
            throw ChatException::messageDeleted();
        }
    }

    // ---------------------------------------------------------------- view once

    /**
     * Open a view-once photo / video / voice note: the files come back this one time only. The
     * sender is told it was opened; nobody (the sender included) can open it again.
     *
     * @return list<array<string, mixed>>
     */
    public function open(Model $me, ChatMessage $message): array
    {
        $participant = $this->conversations->participantOf($me, $message->conversation, true);

        if (! $message->view_once || $message->isGone()) {
            throw ChatException::messageDeleted();
        }
        if ($message->isFrom($participant->participant_type, (int) $participant->participant_id)) {
            throw new ChatException('view_once_own', 403);
        }

        $state = ChatMessageUserState::query()->firstOrNew(['message_id' => $message->id, 'participant_id' => $participant->id]);
        if ($state->opened_at !== null) {
            throw new ChatException('view_once_opened', 410);
        }
        $state->opened_at = now();
        $state->save();

        $this->broadcaster->toParticipants($message->conversation->activeParticipants()->get(), 'chat.view_once.opened', [
            'conversation_id' => $message->conversation->uuid,
            'message_id' => $message->uuid,
            'participant' => $participant->key(),
        ]);

        return $message->load('media')->getMedia(ChatMessage::ATTACHMENTS)->sortBy('order_column')->values()->map(fn ($media) => [
            'id' => $media->uuid,
            'url' => $media->getUrl(),
            'name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'duration_ms' => $media->getCustomProperty('duration_ms'),
        ])->all();
    }

    // ---------------------------------------------------------------- live location

    /**
     * A new position for my live location (until its time runs out or I stop).
     */
    public function moveLive(Model $me, ChatMessage $message, float $latitude, float $longitude, ?float $accuracy = null): ChatMessage
    {
        $participant = $this->assertLiveOwner($me, $message);

        $meta = (array) $message->meta;
        $meta['latitude'] = $latitude;
        $meta['longitude'] = $longitude;
        $meta['accuracy'] = $accuracy;
        $meta['updated_at'] = now()->toIso8601String();
        $message->forceFill(['meta' => $meta])->save();

        $this->broadcaster->toParticipants($message->conversation->activeParticipants()->get(), 'chat.location.moved', [
            'conversation_id' => $message->conversation->uuid,
            'message_id' => $message->uuid,
            'participant' => $participant->key(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $accuracy,
            'updated_at' => $meta['updated_at'],
            'live_until' => $meta['live_until'] ?? null,
        ]);

        return $message;
    }

    public function stopLive(Model $me, ChatMessage $message): ChatMessage
    {
        $this->assertLiveOwner($me, $message);

        $meta = (array) $message->meta;
        $meta['live_until'] = now()->toIso8601String();
        $meta['stopped'] = true;
        $message->forceFill(['meta' => $meta])->save();
        app(MessageService::class)->rebroadcast($message);

        return $message;
    }

    /**
     * My live locations still running — the app resumes sending positions after a restart.
     *
     * @return list<array{message_id: string, conversation_id: string, live_until: string}>
     */
    public function myLive(Model $me): array
    {
        return ChatMessage::query()
            ->where('type', MessageType::Location->value)
            ->where('sender_type', ParticipantType::aliasFor($me))->where('sender_id', $me->getKey())
            ->where('created_at', '>', now()->subSeconds(max(self::LIVE_DURATIONS)))
            ->whereNull('deleted_for_everyone_at')
            ->with('conversation')
            ->get()
            ->filter(fn (ChatMessage $m) => ($until = data_get($m->meta, 'live_until')) !== null && now()->lt($until) && ! data_get($m->meta, 'stopped'))
            ->map(fn (ChatMessage $m) => [
                'message_id' => $m->uuid,
                'conversation_id' => $m->conversation->uuid,
                'live_until' => (string) data_get($m->meta, 'live_until'),
            ])->values()->all();
    }

    private function assertLiveOwner(Model $me, ChatMessage $message): ChatParticipant
    {
        $participant = $this->conversations->participantOf($me, $message->conversation, true);
        $until = data_get($message->meta, 'live_until');

        if (! $message->isFrom($participant->participant_type, (int) $participant->participant_id)) {
            throw ChatException::notYourMessage();
        }
        if ($message->type !== MessageType::Location || $message->isGone() || $until === null || data_get($message->meta, 'stopped') || now()->gte($until)) {
            throw new ChatException('live_location_ended', 422);
        }

        return $participant;
    }
}
