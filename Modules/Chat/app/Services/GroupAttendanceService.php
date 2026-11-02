<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAttendance;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupSession;
use Modules\Chat\Models\ChatGroupSpace;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\Concerns\EducationAccess;
use Modules\Chat\Support\GroupScope;
use Modules\Chat\Support\GroupStudents;

/**
 * Class sessions and attendance in educational groups (docs/groups_tasks.md GR-017):
 *
 * - The teacher (`attendance.manage`) sets a session — for the group or a term / subgroup — and
 *   its students hear about it, and again 30 minutes before (`chat:remind-group-sessions`).
 * - Attendance: present / absent / late / excused, kept by `attendance.manage` only; every change
 *   goes on the activity log with the value before.
 * - A student sees their own record only.
 */
class GroupAttendanceService
{
    use EducationAccess;

    public const PER_PAGE = 15;

    public function __construct(private readonly GroupNotifier $notifier) {}

    /** @return array{sessions: list<array<string, mixed>>, has_more: bool} */
    public function list(Model $me, ChatConversation $conversation, string $when, int $page): array
    {
        $member = $this->educationMember($me, $conversation);
        $manager = in_array('attendance.manage', $this->perms($member, $conversation), true);

        $query = $this->scoped(ChatGroupSession::query(), $me, $conversation, $manager);
        $when === 'past'
            ? $query->where('starts_at', '<', now())->orderByDesc('starts_at')
            : $query->where('starts_at', '>=', now()->subHours(3))->orderBy('starts_at');
        $rows = $query->forPage(max(1, $page), self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();

        return [
            'sessions' => $rows->take(self::PER_PAGE)->map(fn ($s) => $this->present($me, $member, $manager, $s))->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * @param  array{title: string, starts_at: string, ends_at?: ?string, space_id?: ?int}  $data
     * @return array<string, mixed>
     */
    public function create(Model $me, ChatConversation $conversation, array $data): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'attendance.manage');
        GroupLifecycleService::assertWritable($conversation);
        $space = GroupScope::space($conversation, $data['space_id'] ?? null);

        $session = ChatGroupSession::query()->create([
            'conversation_id' => $conversation->id,
            'space_id' => $space?->id,
            'title' => trim($data['title']),
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'created_by_participant_id' => $member->id,
        ]);
        ChatGroupAuditLog::record($conversation, $me, 'session_created', 'session:'.$session->uuid);
        $this->educationChanged($conversation, 'session', $session->uuid);
        $this->notifier->notify($conversation, GroupStudents::of($conversation, $space?->id), 'sessions', 'session_new', ['title' => $session->title], ['session_id' => $session->uuid]);

        return $this->present($me, $member, true, $session);
    }

    public function delete(Model $me, ChatConversation $conversation, string $uuid): void
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'attendance.manage');
        $session = $this->session($me, $conversation, $uuid);
        ChatGroupAuditLog::record($conversation, $me, 'session_deleted', 'session:'.$session->uuid, ['title' => $session->title, 'records' => $session->attendance()->count()]);
        $session->delete();
        $this->educationChanged($conversation, 'session', $uuid);
    }

    /**
     * The register: every student of the session's scope and their status (managers), or my own.
     *
     * @return array<string, mixed>
     */
    public function show(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $member = $this->educationMember($me, $conversation);
        $manager = in_array('attendance.manage', $this->perms($member, $conversation), true);
        $session = $this->session($me, $conversation, $uuid);

        $out = $this->present($me, $member, $manager, $session);
        if ($manager) {
            $records = $session->attendance()->get()->keyBy('participant_id');
            $out['register'] = GroupStudents::of($conversation, $session->space_id)->map(fn (ChatParticipant $p) => $this->person($me, $p->id) + [
                'status' => $records->get($p->id)?->status,
                'note' => $records->get($p->id)?->note,
            ])->values()->all();
        }

        return $out;
    }

    /**
     * Take or correct attendance. Every change is recorded with the value before.
     *
     * @param  list<array{participant_id: int, status: string, note?: ?string}>  $entries
     * @return array<string, mixed>
     */
    public function record(Model $me, ChatConversation $conversation, string $uuid, array $entries): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'attendance.manage');
        $session = $this->session($me, $conversation, $uuid);
        $students = GroupStudents::of($conversation, $session->space_id)->pluck('id')->all();

        DB::transaction(function () use ($me, $conversation, $member, $session, $entries, $students) {
            foreach ($entries as $entry) {
                $pid = (int) $entry['participant_id'];
                if (! in_array($pid, $students, true)) {
                    throw new ChatException('not_a_student', 422);
                }
                $row = ChatGroupAttendance::query()->firstOrNew(['session_id' => $session->id, 'participant_id' => $pid]);
                $before = $row->exists ? ['status' => $row->status, 'note' => $row->note] : null;
                $after = ['status' => $entry['status'], 'note' => $entry['note'] ?? null];
                if ($before === $after) {
                    continue;
                }
                $row->fill($after + ['recorded_by_participant_id' => $member->id])->save();
                ChatGroupAuditLog::record($conversation, $me, 'attendance_set', 'participant:'.$pid, $before, $after + ['session' => $session->uuid]);
            }
        });
        $this->educationChanged($conversation, 'session', $session->uuid);

        return $this->show($me, $conversation, $uuid);
    }

    /**
     * One student's record: mine, or — for `attendance.manage` — anyone's.
     *
     * @return array<string, mixed>
     */
    public function report(Model $me, ChatConversation $conversation, ?int $participantId): array
    {
        $member = $this->educationMember($me, $conversation);
        $manager = in_array('attendance.manage', $this->perms($member, $conversation), true);
        $studentId = $participantId ?? $member->id;
        if ($studentId !== $member->id && ! $manager) {
            throw new ChatException('group_permission_denied', 403);
        }

        $records = ChatGroupAttendance::query()->where('participant_id', $studentId)
            ->whereIn('session_id', ChatGroupSession::query()->where('conversation_id', $conversation->id)->select('id'))
            ->with([])->get()->keyBy('session_id');
        $sessions = ChatGroupSession::query()->whereIn('id', $records->keys())->orderByDesc('starts_at')->limit(100)->get();

        return [
            'student' => $this->person($me, $studentId),
            'counts' => collect(ChatGroupAttendance::STATUSES)->mapWithKeys(fn ($s) => [$s => $records->where('status', $s)->count()])->all(),
            'sessions' => $sessions->map(fn (ChatGroupSession $s) => [
                'id' => $s->uuid, 'title' => $s->title, 'starts_at' => $s->starts_at?->toIso8601String(),
                'status' => $records->get($s->id)?->status, 'note' => $records->get($s->id)?->note,
            ])->values()->all(),
        ];
    }

    /** `chat:remind-group-sessions`: a session starting within 30 minutes — its students hear, once. */
    public function remindSoon(): int
    {
        $count = 0;
        ChatGroupSession::query()->whereNull('reminded_at')->whereBetween('starts_at', [now(), now()->addMinutes(30)])->limit(200)->get()
            ->each(function (ChatGroupSession $s) use (&$count) {
                $s->update(['reminded_at' => now()]);
                $conversation = ChatConversation::query()->with('group')->find($s->conversation_id);
                if ($conversation !== null) {
                    $this->notifier->notify($conversation, GroupStudents::of($conversation, $s->space_id), 'sessions', 'session_soon', ['title' => $s->title], ['session_id' => $s->uuid]);
                    $count++;
                }
            });

        return $count;
    }

    private function session(Model $me, ChatConversation $conversation, string $uuid): ChatGroupSession
    {
        $session = ChatGroupSession::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->first();

        if ($session === null || ! GroupScope::canSee($me, $conversation->id, $session->space_id, 'attendance.manage')) {
            throw new ChatException('session_not_found', 404);
        }

        return $session;
    }

    /** @return array<string, mixed> */
    private function present(Model $me, ChatParticipant $member, bool $manager, ChatGroupSession $s): array
    {
        return [
            'id' => $s->uuid,
            'title' => $s->title,
            'starts_at' => $s->starts_at?->toIso8601String(),
            'ends_at' => $s->ends_at?->toIso8601String(),
            'space' => $s->space_id === null ? null : ['id' => $s->space_id, 'name' => ChatGroupSpace::query()->find($s->space_id)?->conversation?->group?->name],
            // Mine only (a student); managers get the whole register on the session itself.
            'my_status' => ChatGroupAttendance::query()->where('session_id', $s->id)->where('participant_id', $member->id)->value('status'),
            'recorded' => $manager ? ChatGroupAttendance::query()->where('session_id', $s->id)->count() : null,
            'can_manage' => $manager,
        ];
    }
}
