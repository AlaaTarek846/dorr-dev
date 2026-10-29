<?php

namespace Modules\Freelance\Services\Chat;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Chat\Models\ChatCalendarItem;
use Modules\Chat\Services\CalendarService;
use Modules\Freelance\Events\FreelanceMessageSent;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceInterview as Interview;
use Modules\Freelance\Models\FreelanceMessage;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Throwable;

/**
 * A short interview before the agreement (FL-310), arranged inside the chat room of the project: either side proposes a time, the
 * other confirms or declines (a new proposal replaces an open one), either may cancel. A confirmed interview is put in the client's
 * Dorr calendar, written in the room as a system line, and both sides are told before it starts. The talk itself happens in the room.
 */
class FreelanceInterviews
{
    public const MAX_DAYS_AHEAD = 30;

    public const MIN_MINUTES_AHEAD = 5;

    public const DURATIONS = [10, 15, 20, 30, 45, 60];

    /** Both sides are told this long before it starts. */
    public const REMIND_MINUTES = 15;

    public function __construct(
        private readonly FreelanceChatService $chat,
        private readonly FreelanceNotifier $notifier,
        private readonly CalendarService $calendar,
    ) {}

    /** @return list<array<string, mixed>> the open ones first (the next one on top), then the recent past */
    public function list(FreelanceConversation $room): array
    {
        return Interview::query()->where('conversation_id', $room->id)->orderByRaw("status in ('proposed','confirmed') desc")->orderByDesc('starts_at')->limit(30)->get()
            ->map(fn (Interview $i) => $this->present($i))->all();
    }

    public function propose(FreelanceConversation $room, Model $me, CarbonInterface $startsAt, int $duration, ?string $note): Interview
    {
        app(\Modules\Freelance\Services\Moderation\Sanctions::class)->assertAllowed($me, 'chat');
        if (! $this->chat->canWrite($room)) {
            throw new FreelanceException('chat_closed', 409);
        }
        $side = FreelanceChatService::side($me);
        $now = now();
        if ($startsAt->lt($now->copy()->addMinutes(self::MIN_MINUTES_AHEAD)) || $startsAt->gt($now->copy()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new FreelanceException('interview_time_invalid', 422, ['days' => self::MAX_DAYS_AHEAD]);
        }
        if (! in_array($duration, self::DURATIONS, true)) {
            throw new FreelanceException('interview_time_invalid', 422, ['days' => self::MAX_DAYS_AHEAD]);
        }

        $interview = DB::transaction(function () use ($room, $side, $startsAt, $duration, $note): Interview {
            FreelanceConversation::query()->whereKey($room->id)->lockForUpdate()->first();
            // One interview at a time: a new proposal takes the place of the open one.
            foreach (Interview::query()->where('conversation_id', $room->id)->whereIn('status', Interview::OPEN)->lockForUpdate()->get() as $old) {
                $this->release($old, Interview::SUPERSEDED);
            }

            return Interview::query()->create([
                'conversation_id' => $room->id, 'project_id' => $room->context_id, 'client_id' => $room->client_id, 'freelancer_id' => $room->freelancer_id,
                'proposed_by' => $side, 'starts_at' => $startsAt->copy()->utc(), 'duration_minutes' => $duration,
                'note' => ($n = trim((string) $note)) !== '' ? mb_substr($n, 0, 300) : null, 'status' => Interview::PROPOSED,
            ]);
        });

        $this->line($room, $interview, 'interview_line_proposed');
        $this->tell($room, $interview, $side === 'client' ? 'freelancer' : 'client', 'interview.proposed');

        return $interview;
    }

    /** @param  string  $action  confirm | decline | cancel */
    public function respond(FreelanceConversation $room, Model $me, string $uuid, string $action): Interview
    {
        $side = FreelanceChatService::side($me);
        $interview = DB::transaction(function () use ($room, $side, $uuid, $action): Interview {
            $i = Interview::query()->where('uuid', $uuid)->where('conversation_id', $room->id)->lockForUpdate()->firstOrFail();
            if (! in_array($i->status, Interview::OPEN, true) || ($action !== 'cancel' && $i->status !== Interview::PROPOSED)) {
                throw new FreelanceException('interview_not_open', 409);
            }
            if ($action === 'cancel') {
                $this->release($i, Interview::CANCELLED);

                return $i;
            }
            // Only the other side answers a proposal.
            if ($i->proposed_by === $side) {
                throw new FreelanceException('interview_not_yours_to_answer', 403);
            }
            if ($i->starts_at->lte(now())) {
                throw new FreelanceException('interview_time_passed', 409);
            }
            if ($action === 'confirm') {
                $i->forceFill(['status' => Interview::CONFIRMED, 'responded_at' => now()])->save();
            } else {
                $i->forceFill(['status' => Interview::DECLINED, 'responded_at' => now()])->save();
            }

            return $i;
        });

        if ($action === 'confirm') {
            $this->addToCalendar($room, $interview);
        }
        $this->line($room, $interview, 'interview_line_'.$interview->status);
        $this->tell($room, $interview, $side === 'client' ? 'freelancer' : 'client', 'interview.'.$interview->status);

        return $interview;
    }

    /** Tells both sides about interviews starting soon (once each). Returns how many were reminded. */
    public function remindDue(): int
    {
        $n = 0;
        Interview::query()->where('status', Interview::CONFIRMED)->whereNull('reminded_at')
            ->whereBetween('starts_at', [now(), now()->addMinutes(self::REMIND_MINUTES)])->with('conversation')->get()
            ->each(function (Interview $i) use (&$n): void {
                if ($i->conversation === null) {
                    return;
                }
                $i->forceFill(['reminded_at' => now()])->save();
                $this->tell($i->conversation, $i, 'client', 'interview.reminder');
                $this->tell($i->conversation, $i, 'freelancer', 'interview.reminder');
                $n++;
            });

        return $n;
    }

    /** @return array<string, mixed> */
    public function present(Interview $i): array
    {
        return [
            'uuid' => $i->uuid,
            'status' => $i->status,
            'proposed_by' => $i->proposed_by,
            'starts_at' => $i->starts_at->toIso8601String(),
            'duration_minutes' => $i->duration_minutes,
            'note' => $i->note,
            'in_calendar' => $i->calendar_item_id !== null,
        ];
    }

    // ------------------------------------------------------------------

    /** Closes an open interview and takes its calendar item away. */
    private function release(Interview $i, string $status): void
    {
        if ($i->calendar_item_id !== null) {
            ChatCalendarItem::query()->whereKey($i->calendar_item_id)->delete();
        }
        $i->forceFill(['status' => $status, 'responded_at' => now(), 'calendar_item_id' => null])->save();
    }

    private function addToCalendar(FreelanceConversation $room, Interview $i): void
    {
        $client = $room->client;
        if ($client === null || ! $this->calendar->enabled()) {
            return;
        }
        try {
            $project = (string) ($room->context_id ? \Modules\Freelance\Models\FreelanceProject::query()->whereKey($room->context_id)->value('title') : '');
            $result = $this->calendar->create($client, [
                'title' => __('freelance.interview_calendar_title', ['name' => (string) $room->freelancer?->name]),
                'category' => 'meeting',
                'note' => trim($project.($i->note ? "\n".$i->note : '')),
                'starts_at' => $i->starts_at->toIso8601String(),
                'ends_at' => $i->starts_at->copy()->addMinutes($i->duration_minutes)->toIso8601String(),
                'timezone' => 'UTC',
            ]);
            $i->forceFill(['calendar_item_id' => $result['item']->id])->save();
        } catch (Throwable $e) {
            // The interview stands without it.
            Log::warning('[Freelance] interview calendar item failed: '.$e->getMessage());
        }
    }

    /** The system line in the room, live to both sides. */
    private function line(FreelanceConversation $room, Interview $i, string $key): void
    {
        $text = __('freelance.'.$key, ['time' => $i->starts_at->copy()->utc()->format('Y-m-d H:i').' UTC', 'minutes' => $i->duration_minutes]);
        $message = FreelanceMessage::query()->create([
            'conversation_id' => $room->id, 'sender_type' => 'system', 'sender_id' => null, 'kind' => 'system', 'body' => $text, 'created_at' => now(),
        ]);
        $room->forceFill(['last_message_at' => $message->created_at, 'last_message_preview' => mb_substr($text, 0, 120)])->save();
        DB::afterCommit(function () use ($room, $message): void {
            try {
                broadcast(new FreelanceMessageSent($room->uuid, FreelanceChatService::present($message, null)));
            } catch (Throwable $e) {
                Log::warning('[Freelance] interview line broadcast failed: '.$e->getMessage());
            }
        });
    }

    private function tell(FreelanceConversation $room, Interview $i, string $audience, string $event): void
    {
        $receiver = $audience === 'client' ? $room->client : $room->freelancer;
        if ($receiver === null) {
            return;
        }
        $zone = (string) (data_get($receiver, 'timezone') ?: config('app.timezone'));
        $project = (string) \Modules\Freelance\Models\FreelanceProject::query()->whereKey($room->context_id)->value('title');
        $peer = $audience === 'client' ? $room->freelancer : $room->client;
        $this->notifier->notify($receiver, $event, [
            'project' => $project,
            'name' => (string) $peer?->name,
            'time' => $i->starts_at->copy()->setTimezone($zone)->format('Y-m-d H:i'),
        ], ['conversation_uuid' => $room->uuid, 'interview_uuid' => $i->uuid]);
    }
}
