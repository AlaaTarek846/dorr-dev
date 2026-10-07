<?php

namespace Modules\Chat\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatScheduledMessage;
use Modules\Chat\Support\BannedWords;
use Modules\Chat\Support\ParticipantType;

/**
 * Scheduled messages (Telegram style): text written now, sent at a set time by
 * `chat:send-scheduled` (every minute) as its author — through MessageService::send(), so every
 * rule (blocked, left the group, slow mode, banned words…) is checked at the moment it goes out.
 * One that can't be sent stays in the list as `failed` with the reason, to edit or delete.
 *
 * Only the author sees them. Each change is pushed to the author's own devices
 * (`chat.scheduled.changed`) so every open screen shows the same list.
 */
class ScheduledMessageService
{
    public const MAX_PENDING = 100;

    public const MAX_DAYS_AHEAD = 365;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    /**
     * Mine in this chat that haven't gone out yet (waiting or failed), soonest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(Model $me, ChatConversation $conversation): array
    {
        $this->conversations->participantOf($me, $conversation);

        return ChatScheduledMessage::query()->ownedBy($me)->where('conversation_id', $conversation->id)
            ->whereIn('status', [ChatScheduledMessage::PENDING, ChatScheduledMessage::FAILED])
            ->orderBy('send_at')->with('conversation')->get()->map->present()->all();
    }

    public function schedule(Model $me, ChatConversation $conversation, string $body, CarbonInterface $sendAt, bool $silent = false, string $type = 'text', ?array $meta = null, ?string $timezone = null): ChatScheduledMessage
    {
        $participant = $this->conversations->participantOf($me, $conversation, true);

        // What would certainly be refused later is refused now.
        if (($conversation->isChannel() || $conversation->group?->only_admins_send) && ! $participant->isAdmin()) {
            throw ChatException::adminsOnly();
        }
        if ($conversation->isGroup() && ! $participant->isAdmin() && BannedWords::firstIn($body, $conversation->group?->banned_words) !== null) {
            throw new ChatException('banned_word', 422);
        }
        $this->assertTime($sendAt);

        if (ChatScheduledMessage::query()->ownedBy($me)->where('status', ChatScheduledMessage::PENDING)->count() >= self::MAX_PENDING) {
            throw new ChatException('too_many_scheduled', 422, ['max' => self::MAX_PENDING]);
        }

        $row = ChatScheduledMessage::query()->create([
            'conversation_id' => $conversation->id,
            'owner_type' => ParticipantType::aliasFor($me),
            'owner_id' => $me->getKey(),
            'body' => trim($body),
            'type' => $type,
            'meta' => $meta,
            'timezone' => $timezone,
            'is_silent' => $silent,
            'send_at' => $sendAt,
            'status' => ChatScheduledMessage::PENDING,
        ]);

        $this->changed($row);

        return $row;
    }

    /**
     * Change the text, time or silence of one that hasn't gone out. Editing a failed one puts it
     * back in the queue.
     *
     * @param  array{body?: string, send_at?: CarbonInterface, silent?: bool}  $data
     */
    public function update(Model $me, string $uuid, array $data): ChatScheduledMessage
    {
        $row = $this->own($me, $uuid);

        if (isset($data['send_at'])) {
            $this->assertTime($data['send_at']);
            $row->send_at = $data['send_at'];
        } elseif ($row->status === ChatScheduledMessage::FAILED && $row->send_at->isPast()) {
            // A failed one keeps its old (past) time: retry it in a minute.
            $row->send_at = now()->addMinute();
        }
        if (isset($data['body'])) {
            $row->body = trim($data['body']);
        }
        if (array_key_exists('silent', $data)) {
            $row->is_silent = (bool) $data['silent'];
        }
        $row->status = ChatScheduledMessage::PENDING;
        $row->error_code = null;
        $row->save();

        $this->changed($row);

        return $row;
    }

    public function cancel(Model $me, string $uuid): void
    {
        $row = $this->own($me, $uuid);
        $row->delete();
        $this->changed($row, removed: true);
    }

    /**
     * "Send now" — don't wait for the time.
     */
    public function sendNow(Model $me, string $uuid): ChatMessage
    {
        $row = $this->own($me, $uuid);
        $message = $this->deliver($row);

        if ($message === null) {
            throw new ChatException((string) ($row->error_code ?: 'schedule_failed'), 422);
        }

        return $message;
    }

    /**
     * Send everything that's due (the scheduler, every minute). Returns how many went out.
     */
    public function sendDue(int $limit = 500): int
    {
        $sent = 0;

        ChatScheduledMessage::query()->where('status', ChatScheduledMessage::PENDING)->where('send_at', '<=', now())
            ->orderBy('send_at')->limit($limit)->get()
            ->each(function (ChatScheduledMessage $row) use (&$sent) {
                if ($this->deliver($row) !== null) {
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Send one as its author. The row's uuid becomes the message's: sending twice (a retry, or
     * "send now" racing the scheduler) gives the same one message.
     */
    private function deliver(ChatScheduledMessage $row): ?ChatMessage
    {
        $owner = $row->owner();
        $conversation = $row->conversation;

        try {
            if ($owner === null || $conversation === null) {
                throw ChatException::notParticipant();
            }

            $message = $this->messages->send($owner, $conversation, [
                'type' => $row->type ?: 'text',
                'body' => $row->body,
                'uuid' => $row->uuid,
                'silent' => $row->is_silent,
            ] + ($row->type && $row->type !== 'text' ? ['meta_raw' => $row->meta, 'copy_media_from' => $row] : []));
            if ($row->type && $row->type !== 'text') {
                $row->clearMediaCollection(\Modules\Chat\Models\ChatMessage::ATTACHMENTS);
            }

            $row->update(['status' => ChatScheduledMessage::SENT, 'message_id' => $message->id, 'error_code' => null]);
            $this->changed($row, removed: true);

            return $message;
        } catch (ChatException $e) {
            $row->update(['status' => ChatScheduledMessage::FAILED, 'error_code' => 'chat_'.$e->errorCode]);
            $this->changed($row);

            return null;
        }
    }

    private function assertTime(CarbonInterface $sendAt): void
    {
        if ($sendAt->lt(now()->addSeconds(30)) || $sendAt->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new ChatException('schedule_time_invalid', 422, ['days' => self::MAX_DAYS_AHEAD]);
        }
    }

    private function own(Model $me, string $uuid): ChatScheduledMessage
    {
        return ChatScheduledMessage::query()->ownedBy($me)->where('uuid', $uuid)
            ->whereIn('status', [ChatScheduledMessage::PENDING, ChatScheduledMessage::FAILED])
            ->with('conversation')->first()
            ?? throw new ChatException('scheduled_not_found', 404);
    }

    private function changed(ChatScheduledMessage $row, bool $removed = false): void
    {
        $this->broadcaster->toAccounts([[$row->owner_type, (int) $row->owner_id]], 'chat.scheduled.changed', [
            'conversation_id' => $row->conversation?->uuid,
            'id' => $row->uuid,
            'status' => $removed ? 'removed' : $row->status,
            'pending' => ChatScheduledMessage::query()->where('owner_type', $row->owner_type)->where('owner_id', $row->owner_id)
                ->where('conversation_id', $row->conversation_id)->whereIn('status', [ChatScheduledMessage::PENDING, ChatScheduledMessage::FAILED])->count(),
        ]);
    }
}
