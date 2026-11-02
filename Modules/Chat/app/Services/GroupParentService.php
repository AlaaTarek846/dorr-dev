<?php

namespace Modules\Chat\Services;

use App\Support\LocaleResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\CallType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Http\Middleware\EnsureGroupTool;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupParentBooking;
use Modules\Chat\Models\ChatGroupParentLink;
use Modules\Chat\Models\ChatGroupParentMeeting;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\Concerns\EducationAccess;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\GroupStudents;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * Parents in educational groups (docs/groups_tasks.md GR-018, 019, 027 — decision D8).
 *
 * A parent is an ordinary DORR account and never a member of the class chat: nothing of the chat
 * reaches them. The student makes a code, the parent enters it, a teacher (`parents.link`)
 * approves. From then on the parent sees that one child — homework, released grades, attendance,
 * coming classes and tests, announcements — worked out as the child sees it, so nothing of another
 * student can show up. The link ends when the teacher or the parent removes it, or the child leaves.
 *
 * Teachers may let parents message them privately (`parents_can_message`), and hold parents'
 * meetings: one call for everyone, or slots each parent books — both on a LiveKit call link.
 */
class GroupParentService
{
    use EducationAccess;

    private const CODE_DAYS = 7;

    private const REMIND_MINUTES = 30;

    public function __construct(
        private readonly GroupNotifier $notifier,
        private readonly ChatBroadcaster $broadcaster,
        private readonly ChatPushNotifier $push,
        private readonly ParticipantDirectory $directory,
        private readonly ConversationService $conversations,
        private readonly CallLinkService $callLinks,
        private readonly GroupClassExtrasService $extras,
        private readonly GroupAttendanceService $attendance,
    ) {}

    // ================================================================== the student

    /**
     * A new code for my parent (the older unused one goes). Students only.
     *
     * @return array{code: string, expires_at: string}
     */
    public function code(Model $me, ChatConversation $conversation): array
    {
        $member = $this->educationMember($me, $conversation);
        if (! GroupStudents::of($conversation)->contains('id', $member->id)) {
            throw new ChatException('not_a_student', 403);
        }
        $linked = ChatGroupParentLink::query()->where('student_participant_id', $member->id)->whereIn('status', ['pending', 'approved'])->count();
        if ($linked >= ChatGroupParentLink::MAX_PER_STUDENT) {
            throw new ChatException('parent_link_limit', 422, ['max' => ChatGroupParentLink::MAX_PER_STUDENT]);
        }

        ChatGroupParentLink::query()->where('student_participant_id', $member->id)->where('status', 'invited')->delete();
        $link = ChatGroupParentLink::query()->create([
            'conversation_id' => $conversation->id, 'student_participant_id' => $member->id,
            'code' => $this->newCode(), 'code_expires_at' => now()->addDays(self::CODE_DAYS), 'status' => 'invited',
        ]);

        return ['code' => $link->code, 'expires_at' => $link->code_expires_at->toIso8601String()];
    }

    /** @return list<array<string, mixed>> who is linked to me (or waiting) — I always know */
    public function myParents(Model $me, ChatConversation $conversation): array
    {
        $member = $this->educationMember($me, $conversation);

        return ChatGroupParentLink::query()->where('student_participant_id', $member->id)->whereIn('status', ['pending', 'approved'])->latest('id')->get()
            ->map(fn (ChatGroupParentLink $l) => ['id' => $l->uuid, 'status' => $l->status, 'parent' => $this->directory->profile($me, $l->parent_type, $l->parent_id)])
            ->values()->all();
    }

    // ================================================================== the teacher

    /** @return array{messaging: bool, links: list<array<string, mixed>>} */
    public function forTeacher(Model $me, ChatConversation $conversation, ?string $status): array
    {
        $this->teacher($me, $conversation);

        $links = ChatGroupParentLink::query()->where('conversation_id', $conversation->id)
            ->whereIn('status', $status !== null ? [$status] : ['pending', 'approved'])->latest('id')->limit(500)->get();

        return [
            'messaging' => (bool) $conversation->group->parents_can_message,
            'links' => $links->map(fn (ChatGroupParentLink $l) => $this->presentForTeacher($me, $l))->values()->all(),
        ];
    }

    public function decide(Model $me, ChatConversation $conversation, string $uuid, bool $approve): array
    {
        $teacher = $this->teacher($me, $conversation);
        $link = $this->link($conversation, $uuid);
        if ($link->status !== 'pending') {
            throw new ChatException('parent_link_not_found', 404);
        }

        $link->update(['status' => $approve ? 'approved' : 'declined', 'decided_by_participant_id' => $teacher->id, 'decided_at' => now()]);
        ChatGroupAuditLog::record($conversation, $me, $approve ? 'parent_linked' : 'parent_declined', 'participant:'.$link->student_participant_id);
        $this->tellParent($link, $conversation, $approve ? 'parent_approved' : 'parent_declined');

        return $this->presentForTeacher($me, $link->refresh());
    }

    public function unlinkByTeacher(Model $me, ChatConversation $conversation, string $uuid): void
    {
        $this->teacher($me, $conversation);
        $link = $this->link($conversation, $uuid);
        ChatGroupAuditLog::record($conversation, $me, 'parent_unlinked', 'participant:'.$link->student_participant_id);
        $this->tellParent($link, $conversation, 'parent_removed');
        $link->delete();
    }

    public function setMessaging(Model $me, ChatConversation $conversation, bool $on): bool
    {
        $this->teacher($me, $conversation);
        $conversation->group->update(['parents_can_message' => $on]);
        ChatGroupAuditLog::record($conversation, $me, 'parents_messaging', null, null, ['on' => $on]);
        $this->toParents($this->approvedLinks($conversation), 'chat.group.parent', ['what' => 'settings']);

        return $on;
    }

    // ================================================================== the parent

    /** I enter the code my child gave me: the teacher is asked. */
    public function redeem(Model $me, string $code): array
    {
        $link = ChatGroupParentLink::query()->where('code', strtoupper(trim($code)))->where('status', 'invited')
            ->where('code_expires_at', '>', now())->first() ?? throw new ChatException('parent_code_invalid', 404);
        $conversation = $this->conversation($link);
        $student = $link->student;
        if ($student === null || $student->left_at !== null) {
            throw new ChatException('parent_code_invalid', 404);
        }
        if (ParticipantType::key($me) === $student->participant_type.':'.$student->participant_id) {
            throw new ChatException('parent_self', 422);
        }
        $already = ChatGroupParentLink::query()->where('student_participant_id', $student->id)->whereIn('status', ['pending', 'approved'])
            ->where('parent_type', ParticipantType::aliasFor($me))->where('parent_id', $me->getKey())->exists();
        if ($already) {
            throw new ChatException('parent_already_linked', 409);
        }

        $link->update(['parent_type' => ParticipantType::aliasFor($me), 'parent_id' => $me->getKey(), 'status' => 'pending', 'code' => null, 'code_expires_at' => null]);
        $this->notifier->notify($conversation, $this->teachers($conversation, 'parents.link'), 'parents', 'parent_request',
            ['student' => (string) $student->participant()?->name]);
        $this->broadcaster->toParticipants($this->teachers($conversation, 'parents.link'), 'chat.group.parent', ['conversation_id' => $conversation->uuid, 'what' => 'request']);

        return $this->presentForParent($me, $link->refresh());
    }

    /** @return list<array<string, mixed>> my children, approved and waiting */
    public function children(Model $me): array
    {
        return $this->mine($me)->whereIn('status', ['pending', 'approved'])->latest('id')->get()
            ->filter(fn (ChatGroupParentLink $l) => $this->stillThere($l))
            ->map(fn (ChatGroupParentLink $l) => $this->presentForParent($me, $l))->values()->all();
    }

    /**
     * One child's page: worked out as the child sees it — homework, released grades, tests,
     * stars, attendance, coming classes, announcements. Never anything of another student.
     *
     * @return array<string, mixed>
     */
    public function child(Model $me, string $uuid): array
    {
        [$link, $conversation, $child] = $this->approved($me, $uuid);

        $bag = $this->extras->bag($child, $conversation);
        $announcements = ChatMessage::query()->where('conversation_id', $conversation->id)->where('meta->announcement', true)
            ->whereNull('deleted_for_everyone_at')->latest('id')->limit(10)->get()->filter(fn (ChatMessage $m) => ! $m->isGone())
            ->map(fn (ChatMessage $m) => ['id' => $m->uuid, 'body' => $m->body, 'created_at' => $m->created_at?->toIso8601String()])->values()->all();

        return $this->presentForParent($me, $link) + [
            'homework' => $bag['to_hand_in'],
            'grades' => $bag['grades'],
            'tests' => $bag['tests'],
            'stars' => $bag['stars'],
            'attendance' => collect($this->attendance->report($child, $conversation, null))->only(['counts', 'sessions'])->all(),
            'sessions' => $this->attendance->list($child, $conversation, 'upcoming', 1)['sessions'],
            'announcements' => $announcements,
        ];
    }

    public function unlinkByParent(Model $me, string $uuid): void
    {
        $link = $this->mine($me)->where('uuid', $uuid)->first() ?? throw new ChatException('parent_link_not_found', 404);
        $conversation = $this->conversation($link);
        ChatGroupAuditLog::record($conversation, $me, 'parent_unlinked', 'participant:'.$link->student_participant_id);
        $link->delete();
    }

    /** A private chat with one of the teachers — when they allow it (GR-019). */
    public function messageTeacher(Model $me, string $uuid, int $teacherParticipantId): array
    {
        [, $conversation] = $this->approved($me, $uuid);
        if (! $conversation->group->parents_can_message) {
            throw new ChatException('parent_messaging_off', 403);
        }
        $teacher = $this->teachers($conversation, 'lessons.manage')->firstWhere('id', $teacherParticipantId) ?? throw new ChatException('teacher_not_found', 404);
        $account = $teacher->participant() ?? throw new ChatException('teacher_not_found', 404);

        return ['conversation_id' => $this->conversations->openDirect($me, $account)->conversation->uuid];
    }

    // ================================================================== GR-027 parents' meetings

    /** @return list<array<string, mixed>> */
    public function meetingsForTeacher(Model $me, ChatConversation $conversation): array
    {
        $this->teacher($me, $conversation);

        return ChatGroupParentMeeting::query()->where('conversation_id', $conversation->id)->where('starts_at', '>=', now()->subDay())
            ->orderBy('starts_at')->limit(50)->with('callLink', 'bookings')->get()
            ->map(fn ($m) => $this->presentMeeting($me, $m, null, true))->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function createMeeting(Model $me, ChatConversation $conversation, array $data): array
    {
        $teacher = $this->teacher($me, $conversation);
        GroupLifecycleService::assertWritable($conversation);
        $slots = $data['mode'] === 'slots';

        // The call: a LiveKit call link of the teacher's. Without calls here, the meeting still stands.
        try {
            $call = $this->callLinks->create($me, CallType::Video);
        } catch (ChatException) {
            $call = null;
        }
        $meeting = ChatGroupParentMeeting::query()->create([
            'conversation_id' => $conversation->id, 'title' => trim($data['title']), 'note' => $data['note'] ?? null,
            'starts_at' => $data['starts_at'], 'mode' => $data['mode'],
            'slot_minutes' => $slots ? (int) $data['slot_minutes'] : null, 'slots' => $slots ? (int) $data['slots'] : null,
            'call_link_id' => $call?->id, 'created_by_participant_id' => $teacher->id,
        ]);
        ChatGroupAuditLog::record($conversation, $me, 'parent_meeting_created', 'parent_meeting:'.$meeting->uuid);
        $this->tellParents($conversation, $this->approvedLinks($conversation), 'parent_meeting_new',
            ['title' => $meeting->title, 'when' => $meeting->starts_at->format('Y-m-d H:i')], ['meeting_id' => $meeting->uuid]);

        return $this->presentMeeting($me, $meeting->load('callLink', 'bookings'), null, true);
    }

    public function deleteMeeting(Model $me, ChatConversation $conversation, string $uuid): void
    {
        $this->teacher($me, $conversation);
        $meeting = ChatGroupParentMeeting::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->first() ?? throw new ChatException('parent_meeting_not_found', 404);
        $told = $meeting->mode === 'slots'
            ? ChatGroupParentLink::query()->whereIn('id', $meeting->bookings()->pluck('link_id'))->get()
            : $this->approvedLinks($conversation);
        $this->tellParents($conversation, $told, 'parent_meeting_cancelled', ['title' => $meeting->title]);
        $meeting->callLink?->update(['revoked_at' => now()]);
        $meeting->delete();
    }

    /** @return list<array<string, mixed>> the coming meetings of my child's class */
    public function meetingsForParent(Model $me, string $uuid): array
    {
        [$link, $conversation] = $this->approved($me, $uuid);

        return ChatGroupParentMeeting::query()->where('conversation_id', $conversation->id)->where('starts_at', '>=', now()->subHours(3))
            ->orderBy('starts_at')->limit(30)->with('callLink', 'bookings')->get()
            ->map(fn ($m) => $this->presentMeeting($me, $m, $link, false))->values()->all();
    }

    /** One slot per child; the slot must be one of the meeting's, still to come and free. */
    public function book(Model $me, string $uuid, string $meetingUuid, ?string $slotAt): array
    {
        [$link, $conversation] = $this->approved($me, $uuid);
        $meeting = $this->parentMeeting($conversation, $meetingUuid);
        if ($meeting->mode !== 'slots') {
            throw new ChatException('parent_slot_invalid', 422);
        }
        $at = $slotAt !== null ? Carbon::parse($slotAt) : null;
        $slot = collect($meeting->slotTimes())->first(fn (Carbon $t) => $at !== null && $t->equalTo($at));
        if ($slot === null || $slot->isPast()) {
            throw new ChatException('parent_slot_invalid', 422);
        }

        DB::transaction(function () use ($meeting, $link, $slot) {
            ChatGroupParentBooking::query()->where('meeting_id', $meeting->id)->where('link_id', $link->id)->delete();
            if (ChatGroupParentBooking::query()->where('meeting_id', $meeting->id)->where('slot_at', $slot)->lockForUpdate()->exists()) {
                throw new ChatException('parent_slot_taken', 409);
            }
            ChatGroupParentBooking::query()->create(['meeting_id' => $meeting->id, 'link_id' => $link->id, 'slot_at' => $slot]);
        });
        $this->broadcaster->toParticipants($this->teachers($conversation, 'parents.link'), 'chat.group.parent', ['conversation_id' => $conversation->uuid, 'what' => 'booking']);

        return $this->presentMeeting($me, $meeting->refresh()->load('callLink', 'bookings'), $link, false);
    }

    public function cancelBooking(Model $me, string $uuid, string $meetingUuid): void
    {
        [$link, $conversation] = $this->approved($me, $uuid);
        $meeting = $this->parentMeeting($conversation, $meetingUuid);
        ChatGroupParentBooking::query()->where('meeting_id', $meeting->id)->where('link_id', $link->id)->delete();
        $this->broadcaster->toParticipants($this->teachers($conversation, 'parents.link'), 'chat.group.parent', ['conversation_id' => $conversation->uuid, 'what' => 'booking']);
    }

    /** `chat:remind-group-sessions`: a meeting of everyone, or my slot, within 30 minutes — once. */
    public function remindMeetings(): int
    {
        $count = 0;
        $soon = [now(), now()->addMinutes(self::REMIND_MINUTES)];

        ChatGroupParentMeeting::query()->where('mode', 'group')->whereNull('reminded_at')->whereBetween('starts_at', $soon)->limit(200)->get()
            ->each(function (ChatGroupParentMeeting $m) use (&$count) {
                $m->update(['reminded_at' => now()]);
                $conversation = ChatConversation::query()->with('group')->find($m->conversation_id);
                if ($conversation !== null) {
                    $this->tellParents($conversation, $this->approvedLinks($conversation), 'parent_meeting_soon', ['title' => $m->title], ['meeting_id' => $m->uuid]);
                    $count++;
                }
            });

        ChatGroupParentBooking::query()->whereNull('reminded_at')->whereBetween('slot_at', $soon)->limit(500)->get()
            ->each(function (ChatGroupParentBooking $b) use (&$count) {
                $b->update(['reminded_at' => now()]);
                $link = ChatGroupParentLink::query()->find($b->link_id);
                $meeting = ChatGroupParentMeeting::query()->find($b->meeting_id);
                $conversation = $link ? ChatConversation::query()->with('group')->find($link->conversation_id) : null;
                if ($link !== null && $meeting !== null && $conversation !== null) {
                    $this->tellParents($conversation, collect([$link]), 'parent_meeting_soon', ['title' => $meeting->title], ['meeting_id' => $meeting->uuid]);
                    $count++;
                }
            });

        return $count;
    }

    // ================================================================== helpers

    /** A member with `parents.link` in an educational group, the tool on. */
    private function teacher(Model $me, ChatConversation $conversation): ChatParticipant
    {
        $member = $this->educationMember($me, $conversation);
        if (! in_array('parents.link', $this->perms($member, $conversation), true)) {
            throw new ChatException('group_permission_denied', 403);
        }

        return $member;
    }

    /** @return Collection<int, ChatParticipant> active members holding that permission */
    private function teachers(ChatConversation $conversation, string $permission): Collection
    {
        return $conversation->activeParticipants()->get()
            ->filter(fn (ChatParticipant $p) => in_array($permission, GroupPermissions::ofParticipant($p, $conversation), true))->values();
    }

    private function link(ChatConversation $conversation, string $uuid): ChatGroupParentLink
    {
        return ChatGroupParentLink::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)
            ->whereIn('status', ['pending', 'approved'])->first() ?? throw new ChatException('parent_link_not_found', 404);
    }

    private function mine(Model $me)
    {
        return ChatGroupParentLink::query()->where('parent_type', ParticipantType::aliasFor($me))->where('parent_id', $me->getKey());
    }

    /**
     * My approved link, its class and my child's account — checked again on every request: the
     * child still a member and a student, the class still educational, the tool still on.
     *
     * @return array{0: ChatGroupParentLink, 1: ChatConversation, 2: Model}
     */
    private function approved(Model $me, string $uuid): array
    {
        $link = $this->mine($me)->where('uuid', $uuid)->where('status', 'approved')->first();
        if ($link === null || ! $this->stillThere($link)) {
            throw new ChatException('parent_link_not_found', 404);
        }
        if (EnsureGroupTool::disabled('parents')) {
            throw new ChatException('group_tool_disabled', 403);
        }
        $conversation = $this->conversation($link);
        $child = $link->student->participant() ?? throw new ChatException('parent_link_not_found', 404);

        return [$link, $conversation, $child];
    }

    private function stillThere(ChatGroupParentLink $link): bool
    {
        $student = $link->student;
        $conversation = ChatConversation::query()->with('group')->find($link->conversation_id);

        return $student !== null && $student->left_at === null && $conversation !== null
            && GroupLifecycleService::isKindGroup($conversation) && $conversation->group->kind === \Modules\Chat\Enums\GroupKind::Education
            && GroupStudents::of($conversation)->contains('id', $student->id);
    }

    private function conversation(ChatGroupParentLink $link): ChatConversation
    {
        return ChatConversation::query()->with('group')->find($link->conversation_id) ?? throw new ChatException('parent_link_not_found', 404);
    }

    private function parentMeeting(ChatConversation $conversation, string $uuid): ChatGroupParentMeeting
    {
        return ChatGroupParentMeeting::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->first()
            ?? throw new ChatException('parent_meeting_not_found', 404);
    }

    /** @return Collection<int, ChatGroupParentLink> */
    private function approvedLinks(ChatConversation $conversation): Collection
    {
        return ChatGroupParentLink::query()->where('conversation_id', $conversation->id)->where('status', 'approved')->get()
            ->filter(fn ($l) => $this->stillThere($l))->values();
    }

    private function newCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (ChatGroupParentLink::query()->where('code', $code)->exists());

        return $code;
    }

    private function tellParent(ChatGroupParentLink $link, ChatConversation $conversation, string $key): void
    {
        $this->tellParents($conversation, collect([$link]), $key);
    }

    /**
     * Parents aren't members: a live event and a push straight to their accounts.
     *
     * @param  Collection<int, ChatGroupParentLink>  $links
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>  $data
     */
    private function tellParents(ChatConversation $conversation, Collection $links, string $key, array $replace = [], array $data = []): void
    {
        $accounts = $links->filter(fn ($l) => $l->parent_type !== null)->map(fn ($l) => [$l->parent_type, (int) $l->parent_id])->unique(fn ($a) => $a[0].':'.$a[1])->values()->all();
        if ($accounts === []) {
            return;
        }
        $payload = ['conversation_id' => $conversation->uuid, 'what' => $key] + $data;
        $this->broadcaster->toAccounts($accounts, 'chat.group.parent', $payload);

        $headings = $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $headings[$locale] = __("chat.push.{$key}_title", $replace, $locale);
            $contents[$locale] = __("chat.push.{$key}_body", $replace + ['group' => (string) $conversation->group?->name], $locale);
        }
        $this->push->toAccounts($accounts, $headings, $contents, ['type' => 'chat', 'event' => 'chat.group.parent'] + $payload);
    }

    /** @param Collection<int, ChatGroupParentLink> $links */
    private function toParents(Collection $links, string $event, array $payload): void
    {
        $accounts = $links->filter(fn ($l) => $l->parent_type !== null)->map(fn ($l) => [$l->parent_type, (int) $l->parent_id])->values()->all();
        if ($accounts !== []) {
            $this->broadcaster->toAccounts($accounts, $event, $payload);
        }
    }

    /** @return array<string, mixed> */
    private function presentForTeacher(Model $me, ChatGroupParentLink $l): array
    {
        $student = $l->student;

        return [
            'id' => $l->uuid,
            'status' => $l->status,
            'student' => $student ? ['participant_id' => $student->id, 'profile' => $this->directory->profile($me, $student->participant_type, $student->participant_id)] : null,
            'parent' => $this->directory->profile($me, $l->parent_type, $l->parent_id),
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentForParent(Model $me, ChatGroupParentLink $l): array
    {
        $conversation = ChatConversation::query()->with('group')->find($l->conversation_id);
        $student = $l->student;
        $messaging = $l->status === 'approved' && (bool) $conversation?->group?->parents_can_message;

        return [
            'id' => $l->uuid,
            'status' => $l->status,
            'group' => ['id' => $conversation?->uuid, 'name' => $conversation?->group?->name],
            'student' => $student ? $this->directory->profile($me, $student->participant_type, $student->participant_id) : null,
            'messaging' => $messaging,
            'teachers' => $messaging && $conversation !== null
                ? $this->teachers($conversation, 'lessons.manage')->map(fn ($p) => ['participant_id' => $p->id, 'profile' => $this->directory->profile($me, $p->participant_type, $p->participant_id)])->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function presentMeeting(Model $me, ChatGroupParentMeeting $m, ?ChatGroupParentLink $link, bool $teacher): array
    {
        $taken = $m->bookings->keyBy(fn ($b) => $b->slot_at->toIso8601String());
        $mine = $link ? $m->bookings->firstWhere('link_id', $link->id) : null;
        // The call: the teacher, everyone in a meeting of everyone, a parent with a slot.
        $inCall = $teacher || $m->mode === 'group' || $mine !== null;
        $call = $m->callLink;

        return [
            'id' => $m->uuid,
            'title' => $m->title,
            'note' => $m->note,
            'mode' => $m->mode,
            'starts_at' => $m->starts_at?->toIso8601String(),
            'slot_minutes' => $m->slot_minutes,
            'slots' => collect($m->slotTimes())->map(function (Carbon $t) use ($taken, $teacher, $link, $me) {
                $booking = $taken->get($t->toIso8601String());
                $row = ['at' => $t->toIso8601String(), 'free' => $booking === null, 'mine' => $booking !== null && $link !== null && $booking->link_id === $link->id];
                if ($teacher && $booking !== null) {
                    $l = ChatGroupParentLink::query()->find($booking->link_id);
                    $row['parent'] = $l ? $this->directory->profile($me, $l->parent_type, $l->parent_id) : null;
                    $row['student'] = $l?->student ? $this->directory->profile($me, $l->student->participant_type, $l->student->participant_id) : null;
                }

                return $row;
            })->values()->all(),
            'my_slot' => $mine?->slot_at?->toIso8601String(),
            'call' => $inCall && $call !== null && $call->revoked_at === null ? ['token' => $call->token, 'type' => $call->type->value] : null,
        ];
    }
}
