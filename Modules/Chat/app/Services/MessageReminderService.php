<?php

namespace Modules\Chat\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageReminder;
use Modules\Chat\Models\ChatParticipant;

/**
 * "Remind me about this" on any message (spec 47, and 121 "a message tied to a time"): at the
 * time I pick, a push with my note (or the message's text) opens the chat at that message.
 * Sent by `chat:send-reminders` every minute. One reminder per message per person.
 */
class MessageReminderService
{
    public const MAX_DAYS_AHEAD = 365;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ChatPushNotifier $push,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    public function set(Model $me, ChatMessage $message, CarbonInterface $at, ?string $note): ChatMessageReminder
    {
        $participant = $this->conversations->participantOf($me, $message->conversation);

        if ($message->isGone()) {
            throw new ChatException('message_deleted', 410);
        }
        if ($at->lt(now()->addSeconds(30)) || $at->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new ChatException('schedule_time_invalid', 422, ['days' => self::MAX_DAYS_AHEAD]);
        }

        return ChatMessageReminder::query()->updateOrCreate(
            ['message_id' => $message->id, 'participant_id' => $participant->id],
            ['remind_at' => $at, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'sent_at' => null],
        );
    }

    public function clear(Model $me, ChatMessage $message): void
    {
        $participant = $this->conversations->participantOf($me, $message->conversation);
        ChatMessageReminder::query()->where('message_id', $message->id)->where('participant_id', $participant->id)->delete();
    }

    /**
     * My reminders still to come, soonest first, each with its message.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(Model $me): array
    {
        return ChatMessageReminder::query()
            ->whereIn('participant_id', ChatParticipant::query()->of($me)->pluck('id'))
            ->whereNull('sent_at')->orderBy('remind_at')->limit(200)
            ->with('message.conversation')->get()
            ->filter(fn (ChatMessageReminder $r) => $r->message !== null && ! $r->message->isGone())
            ->map(fn (ChatMessageReminder $r) => [
                'remind_at' => $r->remind_at->toIso8601String(),
                'note' => $r->note,
                'message' => $this->messages->presentOne($me, $r->message),
            ])->values()->all();
    }

    /**
     * Everything due (the scheduler, every minute). Returns how many went out.
     */
    public function sendDue(int $limit = 500): int
    {
        $sent = 0;

        ChatMessageReminder::query()->whereNull('sent_at')->where('remind_at', '<=', now())
            ->orderBy('remind_at')->limit($limit)->with(['message.conversation', 'participant'])->get()
            ->each(function (ChatMessageReminder $reminder) use (&$sent) {
                // Marked first: a crash after this never reminds twice.
                $claimed = ChatMessageReminder::query()->whereKey($reminder->id)->whereNull('sent_at')->update(['sent_at' => now()]);
                if (! $claimed || $reminder->message === null || $reminder->participant === null || $reminder->message->isGone()) {
                    return;
                }

                $this->push->reminder($reminder->participant, $reminder->message, $reminder->note);
                $this->broadcaster->toParticipants([$reminder->participant], 'chat.reminder.due', [
                    'conversation_id' => $reminder->message->conversation?->uuid,
                    'message_id' => $reminder->message->uuid,
                    'note' => $reminder->note,
                ]);
                $sent++;
            });

        return $sent;
    }
}
