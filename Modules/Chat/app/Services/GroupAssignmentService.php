<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAssignment;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupGrade;
use Modules\Chat\Models\ChatGroupSubmission;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\Concerns\EducationAccess;
use Modules\Chat\Support\GroupScope;
use Modules\Chat\Support\GroupStudents;

/**
 * Homework in educational groups (docs/groups_tasks.md GR-014):
 *
 * - The teacher (`assignments.manage`) sets it — for the group or a term / subgroup — with files,
 *   a due time and individual or team hand-ins.
 * - A student (`assignments.submit`) hands in; handing in again replaces it and the last version
 *   counts; after the due time it's marked late. In a team, one hand-in covers the team, and nobody
 *   can be in two.
 * - Whoever marks (`grading`) sees every hand-in and gives each student their own grade, even in a
 *   team. A student sees only their own hand-in and grade — never a classmate's.
 */
class GroupAssignmentService
{
    use EducationAccess;

    public const PER_PAGE = 15;

    public const MAX_FILES = 10;

    public function __construct(private readonly GroupNotifier $notifier) {}

    /** @return array{assignments: list<array<string, mixed>>, has_more: bool} */
    public function list(Model $me, ChatConversation $conversation, int $page): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $manager = in_array('assignments.manage', $perms, true) || in_array('grading', $perms, true);

        $rows = $this->scoped(ChatGroupAssignment::query(), $me, $conversation, $manager)
            ->orderByRaw('due_at is null')->orderByDesc('due_at')->orderByDesc('id')
            ->forPage(max(1, $page), self::PER_PAGE)->limit(self::PER_PAGE + 1)->with('media')->get();

        return [
            'assignments' => $rows->take(self::PER_PAGE)->map(fn ($a) => $this->present($me, $conversation, $member, $perms, $a))->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /** @return array<string, mixed> */
    public function show(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $member = $this->educationMember($me, $conversation);

        return $this->present($me, $conversation, $member, $this->perms($member, $conversation), $this->assignment($me, $conversation, $uuid), detail: true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function create(Model $me, ChatConversation $conversation, array $data, array $files): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'assignments.manage');
        GroupLifecycleService::assertWritable($conversation);
        $space = GroupScope::space($conversation, $data['space_id'] ?? null);

        $assignment = DB::transaction(function () use ($me, $conversation, $member, $data, $space, $files) {
            $assignment = ChatGroupAssignment::query()->create([
                'conversation_id' => $conversation->id,
                'space_id' => $space?->id,
                'title' => trim($data['title']),
                'instructions' => $data['instructions'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'mode' => $data['mode'] ?? 'individual',
                'max_score' => $data['max_score'] ?? null,
                'created_by_participant_id' => $member->id,
            ]);
            $this->storeFiles($assignment, $files, ChatGroupAssignment::MATERIALS);
            ChatGroupAuditLog::record($conversation, $me, 'assignment_created', 'assignment:'.$assignment->uuid);

            return $assignment;
        });

        $this->educationChanged($conversation, 'assignment', $assignment->uuid);
        $this->notifier->notify($conversation, GroupStudents::of($conversation, $space?->id), 'assignments', 'assignment_new', ['title' => $assignment->title], ['assignment_id' => $assignment->uuid]);

        return $this->present($me, $conversation, $member, $perms, $assignment->refresh(), detail: true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function update(Model $me, ChatConversation $conversation, string $uuid, array $data, array $files): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'assignments.manage');
        $assignment = $this->assignment($me, $conversation, $uuid);

        $assignment->update(array_intersect_key($data, array_flip(['title', 'instructions', 'due_at', 'max_score'])));
        $this->storeFiles($assignment, $files, ChatGroupAssignment::MATERIALS);
        ChatGroupAuditLog::record($conversation, $me, 'assignment_updated', 'assignment:'.$assignment->uuid);
        $this->educationChanged($conversation, 'assignment', $assignment->uuid);

        return $this->present($me, $conversation, $member, $perms, $assignment->refresh(), detail: true);
    }

    public function delete(Model $me, ChatConversation $conversation, string $uuid): void
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'assignments.manage');
        $assignment = $this->assignment($me, $conversation, $uuid);
        ChatGroupAuditLog::record($conversation, $me, 'assignment_deleted', 'assignment:'.$assignment->uuid, ['title' => $assignment->title]);
        ChatGroupGrade::query()->where('gradable_type', 'assignment')->where('gradable_id', $assignment->id)->delete();
        $assignment->delete();
        $this->educationChanged($conversation, 'assignment', $uuid);
    }

    /**
     * A student hands in (again: it replaces the last one). Team mode: `team` = classmates handing
     * in with me (main-group participant ids) — nobody already in another hand-in.
     *
     * @param  list<int>  $team
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function submit(Model $me, ChatConversation $conversation, string $uuid, ?string $body, array $files, array $team): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'assignments.submit');
        GroupLifecycleService::assertWritable($conversation);
        $assignment = $this->assignment($me, $conversation, $uuid);

        if (blank($body) && $files === []) {
            throw new ChatException('submission_empty', 422);
        }
        if (count($files) > self::MAX_FILES) {
            throw new ChatException('submission_too_many_files', 422, ['max' => self::MAX_FILES]);
        }

        $others = ChatGroupSubmission::query()->where('assignment_id', $assignment->id)->where('participant_id', '!=', $member->id)->get();
        // Already handed in inside a classmate's team: that hand-in is mine too.
        if ($others->contains(fn (ChatGroupSubmission $s) => in_array($member->id, $s->studentIds(), true))) {
            throw new ChatException('already_in_team_submission', 409);
        }

        $teamIds = [];
        if ($assignment->mode === 'team') {
            $students = GroupStudents::of($conversation, $assignment->space_id)->pluck('id')->all();
            $teamIds = array_values(array_unique(array_merge([$member->id], array_map('intval', $team))));
            if (array_diff($teamIds, $students) !== []) {
                throw new ChatException('team_member_not_student', 422);
            }
            $taken = $others->flatMap(fn (ChatGroupSubmission $s) => $s->studentIds())->all();
            if (array_intersect($teamIds, $taken) !== []) {
                throw new ChatException('already_in_team_submission', 409);
            }
        }

        $submission = DB::transaction(function () use ($assignment, $member, $body, $files, $teamIds) {
            $existing = ChatGroupSubmission::query()->where('assignment_id', $assignment->id)->where('participant_id', $member->id)->lockForUpdate()->first();
            $values = [
                'body' => $body,
                'team' => $teamIds ?: null,
                'is_late' => $assignment->due_at !== null && $assignment->due_at->isPast(),
                'submitted_at' => now(),
            ];
            if ($existing !== null) {
                $existing->getMedia(ChatGroupSubmission::FILES)->each->delete();
                $existing->update($values + ['version' => $existing->version + 1]);
                $submission = $existing;
            } else {
                $submission = ChatGroupSubmission::query()->create($values + ['assignment_id' => $assignment->id, 'participant_id' => $member->id]);
            }
            $this->storeFiles($submission, $files, ChatGroupSubmission::FILES);

            return $submission;
        });
        $this->educationChanged($conversation, 'assignment', $assignment->uuid);

        return $this->presentSubmission($me, $submission->refresh(), withGrade: false);
    }

    /**
     * Every hand-in, for whoever marks — with each student's grade.
     *
     * @return array<string, mixed>
     */
    public function submissions(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'grading');
        $assignment = $this->assignment($me, $conversation, $uuid);

        $submissions = ChatGroupSubmission::query()->where('assignment_id', $assignment->id)->with('media')->latest('submitted_at')->get();
        $grades = ChatGroupGrade::query()->where('gradable_type', 'assignment')->where('gradable_id', $assignment->id)->get()->keyBy('participant_id');
        $handedIn = $submissions->flatMap(fn ($s) => $s->studentIds())->all();

        return [
            'submissions' => $submissions->map(fn ($s) => $this->presentSubmission($me, $s, withGrade: false) + [
                'grades' => collect($s->studentIds())->map(fn ($id) => ['participant_id' => $id] + $this->presentGrade($grades->get($id)))->values()->all(),
            ])->values()->all(),
            // Students who haven't handed in (yet).
            'missing' => GroupStudents::of($conversation, $assignment->space_id)->reject(fn ($p) => in_array($p->id, $handedIn, true))
                ->map(fn ($p) => $this->person($me, $p->id) + $this->presentGrade($grades->get($p->id)))->values()->all(),
        ];
    }

    /**
     * One student's grade (each one their own, even in a team). Sensitive: on the activity log.
     *
     * @return array<string, mixed>
     */
    public function grade(Model $me, ChatConversation $conversation, string $uuid, int $participantId, ?float $score, ?string $feedback): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'grading');
        $assignment = $this->assignment($me, $conversation, $uuid);

        $student = GroupStudents::of($conversation, $assignment->space_id)->firstWhere('id', $participantId) ?? throw new ChatException('not_a_student', 422);
        if ($score !== null && $assignment->max_score !== null && $score > $assignment->max_score) {
            throw new ChatException('score_above_max', 422, ['max' => $assignment->max_score]);
        }

        $grade = ChatGroupGrade::query()->firstOrNew(['gradable_type' => 'assignment', 'gradable_id' => $assignment->id, 'participant_id' => $student->id]);
        $before = $grade->exists ? ['score' => $grade->score, 'feedback' => $grade->feedback] : null;
        $grade->fill(['score' => $score, 'feedback' => $feedback, 'graded_by_participant_id' => $member->id, 'graded_at' => now()])->save();
        ChatGroupAuditLog::record($conversation, $me, 'grade_set', 'participant:'.$student->id, $before, ['assignment' => $assignment->uuid, 'score' => $score, 'feedback' => $feedback]);

        $this->notifier->notify($conversation, [$student], 'grades', 'grade_ready', ['title' => $assignment->title], ['assignment_id' => $assignment->uuid]);

        return ['participant_id' => $student->id] + $this->presentGrade($grade);
    }

    // ------------------------------------------------------------------ presenting

    /**
     * @param  list<string>  $perms
     * @return array<string, mixed>
     */
    private function present(Model $me, ChatConversation $conversation, ChatParticipant $member, array $perms, ChatGroupAssignment $a, bool $detail = false): array
    {
        $grader = in_array('grading', $perms, true);
        $mine = ChatGroupSubmission::query()->where('assignment_id', $a->id)->get()
            ->first(fn (ChatGroupSubmission $s) => in_array($member->id, $s->studentIds(), true));
        $myGrade = ChatGroupGrade::query()->where('gradable_type', 'assignment')->where('gradable_id', $a->id)->where('participant_id', $member->id)->first();

        return [
            'id' => $a->uuid,
            'title' => $a->title,
            'instructions' => $a->instructions,
            'due_at' => $a->due_at?->toIso8601String(),
            'is_past_due' => $a->due_at !== null && $a->due_at->isPast(),
            'mode' => $a->mode,
            'max_score' => $a->max_score,
            'space' => $a->space_id === null ? null : ['id' => $a->space_id, 'name' => \Modules\Chat\Models\ChatGroupSpace::query()->find($a->space_id)?->conversation?->group?->name],
            'files' => $detail ? $this->filesOf($a, ChatGroupAssignment::MATERIALS) : [],
            // Mine only: my hand-in (or my team's) and my own grade.
            'my_submission' => $mine ? $this->presentSubmission($me, $mine, withGrade: false) : null,
            'my_grade' => $myGrade?->graded_at ? $this->presentGrade($myGrade) : null,
            'submissions_count' => $grader ? ChatGroupSubmission::query()->where('assignment_id', $a->id)->count() : null,
            'can' => [
                'manage' => in_array('assignments.manage', $perms, true),
                'submit' => in_array('assignments.submit', $perms, true),
                'grade' => $grader,
            ],
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentSubmission(Model $me, ChatGroupSubmission $s, bool $withGrade): array
    {
        return [
            'id' => $s->id,
            'by' => $this->person($me, $s->participant_id),
            'team' => $s->team ? collect($s->team)->map(fn ($id) => $this->person($me, (int) $id))->filter()->values()->all() : null,
            'body' => $s->body,
            'files' => $this->filesOf($s, ChatGroupSubmission::FILES),
            'version' => $s->version,
            'is_late' => $s->is_late,
            'submitted_at' => $s->submitted_at?->toIso8601String(),
        ];
    }

    /** @return array{score: ?float, feedback: ?string, graded_at: ?string} */
    private function presentGrade(?ChatGroupGrade $grade): array
    {
        return ['score' => $grade?->score, 'feedback' => $grade?->feedback, 'graded_at' => $grade?->graded_at?->toIso8601String()];
    }

    private function assignment(Model $me, ChatConversation $conversation, string $uuid): ChatGroupAssignment
    {
        $assignment = ChatGroupAssignment::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->with('media')->first();

        if ($assignment === null || ! GroupScope::canSee($me, $conversation->id, $assignment->space_id, 'assignments.manage')) {
            throw new ChatException('assignment_not_found', 404);
        }

        return $assignment;
    }
}
