<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupBadge;
use Modules\Chat\Models\ChatGroupGoal;
use Modules\Chat\Models\ChatGroupGoalPoint;
use Modules\Chat\Models\ChatGroupIdea;
use Modules\Chat\Models\ChatGroupIdeaVote;
use Modules\Chat\Models\ChatGroupMeeting;
use Modules\Chat\Models\ChatGroupShift;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\GroupScope;
use Modules\Chat\Support\GroupStudents;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * Work group extras (docs/groups_tasks.md GR-040, 042, 043, 044, 046 without AI):
 *
 * - Shifts (`shifts.manage` sets them): the member writes a hand-over note for whoever comes next
 *   (who is told), and can ask another member to swap — the swap happens when that member agrees.
 * - Team competitions and badges, given by `tasks.approve`; the leaderboard is for everyone.
 * - The ideas bank: anyone proposes and votes (once each); `tasks.approve` sets the status.
 * - Meetings (`tasks.assign`): time, agenda, minutes — and tasks made from the minutes' lines.
 * Every rule is checked on the server; the open apps reload on `chat.group.work`.
 */
class GroupWorkExtrasService
{
    public function __construct(
        private readonly GroupNotifier $notifier,
        private readonly ChatBroadcaster $broadcaster,
        private readonly GroupTaskService $tasks,
    ) {}

    // ------------------------------------------------------------------ GR-040 shifts

    /** @return list<array<string, mixed>> the shifts from a day on (a week by default), or mine */
    public function shifts(Model $me, ChatConversation $conversation, ?string $from, bool $mine): array
    {
        $member = $this->workMember($me, $conversation);
        $start = $from ? \Illuminate\Support\Carbon::parse($from)->startOfDay() : now()->startOfDay();

        return ChatGroupShift::query()->where('conversation_id', $conversation->id)
            ->where('ends_at', '>=', $start)->where('starts_at', '<', $start->copy()->addDays(7))
            ->when($mine, fn ($q) => $q->where(fn ($q) => $q->where('participant_id', $member->id)->orWhere('swap_with_participant_id', $member->id)))
            ->orderBy('starts_at')->limit(300)->get()
            ->map(fn ($s) => $this->presentShift($me, $member, $conversation, $s))->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function createShift(Model $me, ChatConversation $conversation, array $data): array
    {
        $member = $this->workMember($me, $conversation);
        $this->need($member, $conversation, 'shifts.manage');
        GroupLifecycleService::assertWritable($conversation);
        $who = $conversation->activeParticipants()->find((int) $data['participant_id']) ?? throw new ChatException('not_a_member', 404);

        $shift = ChatGroupShift::query()->create([
            'conversation_id' => $conversation->id, 'participant_id' => $who->id, 'title' => $data['title'] ?? null,
            'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'created_by_participant_id' => $member->id,
        ]);
        ChatGroupAuditLog::record($conversation, $me, 'shift_created', 'participant:'.$who->id, null, ['starts_at' => $shift->starts_at->toIso8601String()]);
        $this->changed($conversation, 'shift');
        if ($who->id !== $member->id) {
            $this->notifier->notify($conversation, [$who], 'shifts', 'shift_new', ['when' => $shift->starts_at->format('Y-m-d H:i')], ['shift_id' => $shift->uuid]);
        }

        return $this->presentShift($me, $member, $conversation, $shift);
    }

    public function deleteShift(Model $me, ChatConversation $conversation, string $uuid): void
    {
        $member = $this->workMember($me, $conversation);
        $this->need($member, $conversation, 'shifts.manage');
        $shift = $this->shift($conversation, $uuid);
        ChatGroupAuditLog::record($conversation, $me, 'shift_deleted', 'participant:'.$shift->participant_id);
        $shift->delete();
        $this->changed($conversation, 'shift');
    }

    /** The member hands over: a note for whoever has the next shift (who is told). */
    public function handover(Model $me, ChatConversation $conversation, string $uuid, string $note): array
    {
        $member = $this->workMember($me, $conversation);
        $shift = $this->shift($conversation, $uuid);
        if ($shift->participant_id !== $member->id) {
            throw new ChatException('group_permission_denied', 403);
        }

        $shift->update(['handover_note' => trim($note), 'handed_over_at' => now()]);
        $next = ChatGroupShift::query()->where('conversation_id', $conversation->id)->where('starts_at', '>=', $shift->starts_at)
            ->where('id', '!=', $shift->id)->where('participant_id', '!=', $member->id)->orderBy('starts_at')->first();
        $nextMember = $next ? ChatParticipant::query()->find($next->participant_id) : null;
        if ($nextMember !== null) {
            $this->notifier->notify($conversation, [$nextMember], 'shifts', 'shift_handover', ['note' => mb_strimwidth(trim($note), 0, 80, '…')], ['shift_id' => $next->uuid]);
        }
        $this->changed($conversation, 'shift');

        return $this->presentShift($me, $member, $conversation, $shift->refresh());
    }

    /** Ask another member to take my shift; or (null) take the ask back. */
    public function askSwap(Model $me, ChatConversation $conversation, string $uuid, ?int $withParticipantId): array
    {
        $member = $this->workMember($me, $conversation);
        $shift = $this->shift($conversation, $uuid);
        if ($shift->participant_id !== $member->id || $shift->starts_at->isPast()) {
            throw new ChatException('group_permission_denied', 403);
        }

        $with = null;
        if ($withParticipantId !== null) {
            $with = $conversation->activeParticipants()->find($withParticipantId) ?? throw new ChatException('not_a_member', 404);
            if ($with->id === $member->id) {
                throw new ChatException('group_permission_denied', 403);
            }
        }
        $shift->update(['swap_with_participant_id' => $with?->id]);
        if ($with !== null) {
            $this->notifier->notify($conversation, [$with], 'shifts', 'shift_swap', ['when' => $shift->starts_at->format('Y-m-d H:i')], ['shift_id' => $shift->uuid]);
        }
        $this->changed($conversation, 'shift');

        return $this->presentShift($me, $member, $conversation, $shift->refresh());
    }

    /** The member asked agrees (the shift is theirs) or declines. */
    public function answerSwap(Model $me, ChatConversation $conversation, string $uuid, bool $accept): array
    {
        $member = $this->workMember($me, $conversation);
        $shift = $this->shift($conversation, $uuid);
        if ($shift->swap_with_participant_id !== $member->id) {
            throw new ChatException('swap_not_found', 404);
        }

        $from = $shift->participant_id;
        $shift->update($accept ? ['participant_id' => $member->id, 'swap_with_participant_id' => null] : ['swap_with_participant_id' => null]);
        if ($accept) {
            ChatGroupAuditLog::record($conversation, $me, 'shift_swapped', 'participant:'.$member->id, ['participant' => $from], ['participant' => $member->id]);
        }
        $asker = ChatParticipant::query()->find($from);
        if ($asker !== null) {
            $this->notifier->notify($conversation, [$asker], 'shifts', $accept ? 'shift_swap_accepted' : 'shift_swap_declined', [], ['shift_id' => $shift->uuid]);
        }
        $this->changed($conversation, 'shift');

        return $this->presentShift($me, $member, $conversation, $shift->refresh());
    }

    // ------------------------------------------------------------------ GR-042 team competitions

    /** @return list<array<string, mixed>> */
    public function goals(Model $me, ChatConversation $conversation): array
    {
        $this->workMember($me, $conversation);

        return ChatGroupGoal::query()->where('conversation_id', $conversation->id)->latest('id')->limit(50)->get()
            ->map(fn (ChatGroupGoal $g) => $this->presentGoal($me, $g))->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function createGoal(Model $me, ChatConversation $conversation, array $data): array
    {
        $member = $this->workMember($me, $conversation);
        $this->need($member, $conversation, 'tasks.approve');
        $goal = ChatGroupGoal::query()->create([
            'conversation_id' => $conversation->id, 'title' => trim($data['title']), 'description' => $data['description'] ?? null,
            'ends_at' => $data['ends_at'] ?? null, 'created_by_participant_id' => $member->id,
        ]);
        $this->changed($conversation, 'goal');

        return $this->presentGoal($me, $goal);
    }

    public function givePoints(Model $me, ChatConversation $conversation, string $uuid, int $participantId, int $points, ?string $note): array
    {
        $member = $this->workMember($me, $conversation);
        $this->need($member, $conversation, 'tasks.approve');
        $goal = ChatGroupGoal::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->first() ?? throw new ChatException('goal_not_found', 404);
        $who = $conversation->activeParticipants()->find($participantId) ?? throw new ChatException('not_a_member', 404);

        ChatGroupGoalPoint::query()->create(['goal_id' => $goal->id, 'participant_id' => $who->id, 'points' => $points, 'note' => $note, 'given_by_participant_id' => $member->id]);
        ChatGroupAuditLog::record($conversation, $me, 'points_given', 'participant:'.$who->id, null, ['goal' => $goal->uuid, 'points' => $points]);
        $this->changed($conversation, 'goal');

        return $this->presentGoal($me, $goal);
    }

    // ------------------------------------------------------------------ GR-043 badges

    /** @return list<array<string, mixed>> everyone's, or one member's */
    public function badges(Model $me, ChatConversation $conversation, ?int $participantId): array
    {
        $this->workMember($me, $conversation);

        return ChatGroupBadge::query()->where('conversation_id', $conversation->id)
            ->when($participantId, fn ($q, $id) => $q->where('participant_id', $id))->latest('id')->limit(200)->get()
            ->map(fn (ChatGroupBadge $b) => ['id' => $b->id, 'emoji' => $b->emoji, 'title' => $b->title, 'reason' => $b->reason,
                'member' => $this->person($me, $b->participant_id), 'created_at' => $b->created_at?->toIso8601String()])->values()->all();
    }

    public function giveBadge(Model $me, ChatConversation $conversation, int $participantId, string $title, ?string $emoji, ?string $reason): array
    {
        $member = $this->workMember($me, $conversation);
        $this->need($member, $conversation, 'tasks.approve');
        $who = $conversation->activeParticipants()->find($participantId) ?? throw new ChatException('not_a_member', 404);

        $badge = ChatGroupBadge::query()->create([
            'conversation_id' => $conversation->id, 'participant_id' => $who->id, 'emoji' => $emoji ?: '🏅',
            'title' => trim($title), 'reason' => $reason, 'given_by_participant_id' => $member->id,
        ]);
        $this->notifier->notify($conversation, [$who], 'grades', 'badge_given', ['title' => $badge->emoji.' '.$badge->title]);
        $this->changed($conversation, 'badge');

        return ['id' => $badge->id, 'emoji' => $badge->emoji, 'title' => $badge->title, 'member' => $this->person($me, $who->id)];
    }

    // ------------------------------------------------------------------ GR-044 ideas

    /** @return list<array<string, mixed>> */
    public function ideas(Model $me, ChatConversation $conversation, ?string $status): array
    {
        $member = $this->workMember($me, $conversation);
        $mine = ChatGroupIdeaVote::query()->where('participant_id', $member->id)->pluck('idea_id')->all();

        return ChatGroupIdea::query()->where('conversation_id', $conversation->id)
            ->when($status, fn ($q, $s) => $q->where('status', $s))->orderByDesc('votes')->latest('id')->limit(100)->get()
            ->map(fn (ChatGroupIdea $i) => $this->presentIdea($me, $i, in_array($i->id, $mine, true)))->values()->all();
    }

    public function propose(Model $me, ChatConversation $conversation, string $title, ?string $body): array
    {
        $member = $this->workMember($me, $conversation);
        GroupLifecycleService::assertWritable($conversation);
        $idea = ChatGroupIdea::query()->create(['conversation_id' => $conversation->id, 'participant_id' => $member->id, 'title' => trim($title), 'body' => $body]);
        $this->changed($conversation, 'idea');

        return $this->presentIdea($me, $idea, false);
    }

    /** One vote each (again: takes it back). */
    public function vote(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $member = $this->workMember($me, $conversation);
        $idea = $this->idea($conversation, $uuid);

        $voted = DB::transaction(function () use ($idea, $member) {
            $existing = ChatGroupIdeaVote::query()->where('idea_id', $idea->id)->where('participant_id', $member->id)->first();
            $existing ? $existing->delete() : ChatGroupIdeaVote::query()->create(['idea_id' => $idea->id, 'participant_id' => $member->id]);
            $idea->update(['votes' => ChatGroupIdeaVote::query()->where('idea_id', $idea->id)->count()]);

            return $existing === null;
        });
        $this->changed($conversation, 'idea');

        return $this->presentIdea($me, $idea->refresh(), $voted);
    }

    public function setIdeaStatus(Model $me, ChatConversation $conversation, string $uuid, string $status, ?string $note): array
    {
        $member = $this->workMember($me, $conversation);
        $this->need($member, $conversation, 'tasks.approve');
        $idea = $this->idea($conversation, $uuid);
        $before = $idea->status;
        $idea->update(['status' => $status, 'status_note' => $note]);
        ChatGroupAuditLog::record($conversation, $me, 'idea_status', 'idea:'.$idea->uuid, ['status' => $before], ['status' => $status]);
        $owner = ChatParticipant::query()->find($idea->participant_id);
        if ($owner !== null && $owner->id !== $member->id) {
            $this->notifier->notify($conversation, [$owner], 'ideas', 'idea_status', ['title' => $idea->title, 'status' => __('chat.idea_status.'.$status)]);
        }
        $this->changed($conversation, 'idea');

        return $this->presentIdea($me, $idea->refresh(), ChatGroupIdeaVote::query()->where('idea_id', $idea->id)->where('participant_id', $member->id)->exists());
    }

    // ------------------------------------------------------------------ GR-046 meetings (no AI)

    /** @return list<array<string, mixed>> */
    public function meetings(Model $me, ChatConversation $conversation, string $when): array
    {
        $member = $this->workMember($me, $conversation);
        $query = ChatGroupMeeting::query()->where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->whereNull('space_id')->orWhereIn('space_id', GroupScope::spaceIds($me, $conversation->id)));
        $when === 'past' ? $query->where('starts_at', '<', now())->orderByDesc('starts_at') : $query->where('starts_at', '>=', now()->subHours(3))->orderBy('starts_at');

        return $query->limit(50)->get()->map(fn ($m) => $this->presentMeeting($me, $member, $conversation, $m))->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function createMeeting(Model $me, ChatConversation $conversation, array $data): array
    {
        $member = $this->workMember($me, $conversation);
        $this->need($member, $conversation, 'tasks.assign');
        GroupLifecycleService::assertWritable($conversation);
        $space = GroupScope::space($conversation, $data['space_id'] ?? null);

        $meeting = ChatGroupMeeting::query()->create([
            'conversation_id' => $conversation->id, 'space_id' => $space?->id, 'title' => trim($data['title']),
            'starts_at' => $data['starts_at'], 'agenda' => $data['agenda'] ?? null, 'created_by_participant_id' => $member->id,
        ]);
        $this->notifier->notify($conversation, GroupStudents::audience($conversation, $space?->id)->reject(fn ($p) => $p->id === $member->id), 'meetings', 'meeting_new',
            ['title' => $meeting->title, 'when' => $meeting->starts_at->format('Y-m-d H:i')], ['meeting_id' => $meeting->uuid]);
        $this->changed($conversation, 'meeting');

        return $this->presentMeeting($me, $member, $conversation, $meeting);
    }

    /** The minutes, written by hand (its maker or `tasks.assign`). */
    public function saveMinutes(Model $me, ChatConversation $conversation, string $uuid, ?string $agenda, ?string $minutes): array
    {
        $member = $this->workMember($me, $conversation);
        $meeting = $this->meeting($me, $conversation, $uuid);
        $this->assertMeetingEditor($member, $conversation, $meeting);

        $meeting->update(array_filter(['agenda' => $agenda, 'minutes' => $minutes, 'minutes_by_participant_id' => $minutes !== null ? $member->id : null], fn ($v) => $v !== null));
        ChatGroupAuditLog::record($conversation, $me, 'minutes_saved', 'meeting:'.$meeting->uuid);
        $this->changed($conversation, 'meeting');

        return $this->presentMeeting($me, $member, $conversation, $meeting->refresh());
    }

    /**
     * Tasks from the minutes: each given line becomes a task of this group (the usual task rules).
     *
     * @param  list<array{title: string, assignee_participant_id?: ?int, due_at?: ?string}>  $items
     * @return list<array<string, mixed>>
     */
    public function tasksFromMinutes(Model $me, ChatConversation $conversation, string $uuid, array $items): array
    {
        $member = $this->workMember($me, $conversation);
        $meeting = $this->meeting($me, $conversation, $uuid);
        $this->assertMeetingEditor($member, $conversation, $meeting);

        return collect($items)->filter(fn ($i) => filled($i['title'] ?? null))->take(30)
            ->map(fn ($i) => $this->tasks->create($me, $conversation, [
                'title' => $i['title'], 'assignee_participant_id' => $i['assignee_participant_id'] ?? null, 'due_at' => $i['due_at'] ?? null,
                'description' => __('chat.meeting_task_from', ['title' => $meeting->title]), 'space_id' => $meeting->space_id,
            ]))->values()->all();
    }

    /** `chat:remind-group-sessions` also reminds a meeting's members 30 minutes before, once. */
    public function remindMeetings(): int
    {
        $count = 0;
        ChatGroupMeeting::query()->whereNull('reminded_at')->whereBetween('starts_at', [now(), now()->addMinutes(30)])->limit(200)->get()
            ->each(function (ChatGroupMeeting $m) use (&$count) {
                $m->update(['reminded_at' => now()]);
                $conversation = ChatConversation::query()->with('group')->find($m->conversation_id);
                if ($conversation !== null) {
                    $this->notifier->notify($conversation, GroupStudents::audience($conversation, $m->space_id), 'meetings', 'meeting_soon', ['title' => $m->title], ['meeting_id' => $m->uuid]);
                    $count++;
                }
            });

        return $count;
    }

    // ------------------------------------------------------------------ helpers

    private function workMember(Model $me, ChatConversation $conversation): ChatParticipant
    {
        if (! GroupLifecycleService::isKindGroup($conversation) || $conversation->group->kind !== GroupKind::Work) {
            throw new ChatException('work_group_only', 422);
        }

        return GroupScope::member($me, $conversation->id) ?? throw ChatException::notParticipant();
    }

    private function need(ChatParticipant $member, ChatConversation $conversation, string $permission): void
    {
        if (! in_array($permission, GroupPermissions::ofParticipant($member, $conversation), true)) {
            throw new ChatException('group_permission_denied', 403);
        }
    }

    private function shift(ChatConversation $conversation, string $uuid): ChatGroupShift
    {
        return ChatGroupShift::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->first() ?? throw new ChatException('shift_not_found', 404);
    }

    private function idea(ChatConversation $conversation, string $uuid): ChatGroupIdea
    {
        return ChatGroupIdea::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->first() ?? throw new ChatException('idea_not_found', 404);
    }

    private function meeting(Model $me, ChatConversation $conversation, string $uuid): ChatGroupMeeting
    {
        $meeting = ChatGroupMeeting::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->first();
        if ($meeting === null || ! GroupScope::canSee($me, $conversation->id, $meeting->space_id, 'tasks.assign')) {
            throw new ChatException('meeting_not_found', 404);
        }

        return $meeting;
    }

    private function assertMeetingEditor(ChatParticipant $member, ChatConversation $conversation, ChatGroupMeeting $meeting): void
    {
        if ($meeting->created_by_participant_id !== $member->id) {
            $this->need($member, $conversation, 'tasks.assign');
        }
    }

    /** @return array{participant_id: int, profile: ?array<string, mixed>}|null */
    private function person(Model $me, ?int $id): ?array
    {
        $row = $id ? ChatParticipant::query()->find($id) : null;

        return $row ? ['participant_id' => $row->id, 'profile' => app(ParticipantDirectory::class)->profile($me, $row->participant_type, $row->participant_id)] : null;
    }

    /** @return array<string, mixed> */
    private function presentShift(Model $me, ChatParticipant $member, ChatConversation $conversation, ChatGroupShift $s): array
    {
        return [
            'id' => $s->uuid, 'title' => $s->title,
            'starts_at' => $s->starts_at?->toIso8601String(), 'ends_at' => $s->ends_at?->toIso8601String(),
            'member' => $this->person($me, $s->participant_id),
            'handover_note' => $s->handover_note, 'handed_over_at' => $s->handed_over_at?->toIso8601String(),
            'swap_with' => $this->person($me, $s->swap_with_participant_id),
            'can' => [
                'manage' => in_array('shifts.manage', GroupPermissions::ofParticipant($member, $conversation), true),
                'handover' => $s->participant_id === $member->id,
                'ask_swap' => $s->participant_id === $member->id && $s->starts_at?->isFuture(),
                'answer_swap' => $s->swap_with_participant_id === $member->id,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentGoal(Model $me, ChatGroupGoal $g): array
    {
        $rank = 0;

        return [
            'id' => $g->uuid, 'title' => $g->title, 'description' => $g->description, 'ends_at' => $g->ends_at?->toIso8601String(),
            'leaderboard' => $g->points()->get()->groupBy('participant_id')
                ->map(fn ($rows, $pid) => ['member' => $this->person($me, (int) $pid), 'points' => (int) $rows->sum('points')])
                ->sortByDesc('points')->values()->map(function ($row) use (&$rank) {
                    return ['rank' => ++$rank] + $row;
                })->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentIdea(Model $me, ChatGroupIdea $i, bool $voted): array
    {
        return [
            'id' => $i->uuid, 'title' => $i->title, 'body' => $i->body, 'status' => $i->status, 'status_note' => $i->status_note,
            'votes' => $i->votes, 'voted' => $voted, 'by' => $this->person($me, $i->participant_id), 'created_at' => $i->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentMeeting(Model $me, ChatParticipant $member, ChatConversation $conversation, ChatGroupMeeting $m): array
    {
        return [
            'id' => $m->uuid, 'title' => $m->title, 'starts_at' => $m->starts_at?->toIso8601String(),
            'agenda' => $m->agenda, 'minutes' => $m->minutes, 'minutes_by' => $this->person($me, $m->minutes_by_participant_id),
            'can_edit' => $m->created_by_participant_id === $member->id || in_array('tasks.assign', GroupPermissions::ofParticipant($member, $conversation), true),
        ];
    }

    private function changed(ChatConversation $conversation, string $what): void
    {
        $this->broadcaster->toParticipants($conversation->activeParticipants()->get(), 'chat.group.work', ['conversation_id' => $conversation->uuid, 'what' => $what]);
    }
}
