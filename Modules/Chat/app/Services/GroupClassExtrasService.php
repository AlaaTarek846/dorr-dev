<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAssignment;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupCertificate;
use Modules\Chat\Models\ChatGroupGrade;
use Modules\Chat\Models\ChatGroupLesson;
use Modules\Chat\Models\ChatGroupQuiz;
use Modules\Chat\Models\ChatGroupQuizAttempt;
use Modules\Chat\Models\ChatGroupReadingItem;
use Modules\Chat\Models\ChatGroupReadingProgress;
use Modules\Chat\Models\ChatGroupStar;
use Modules\Chat\Models\ChatGroupSubmission;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\Concerns\EducationAccess;
use Modules\Chat\Support\CertificatePdf;
use Modules\Chat\Support\GroupMedia;
use Modules\Chat\Support\GroupScope;
use Modules\Chat\Support\GroupStudents;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * Educational group extras (docs/groups_tasks.md GR-021, 022, 024, 025, 026, 028, 030):
 * the student's bag, the tests calendar and its reminders, class competitions, stars of the week,
 * reading lists, certificates and surveys whose results only the teachers see. Each is checked
 * on the server; a student only ever sees their own marks.
 */
class GroupClassExtrasService
{
    use EducationAccess;

    public function __construct(
        private readonly GroupNotifier $notifier,
        private readonly MessageService $messages,
    ) {}

    // ------------------------------------------------------------------ GR-021 the student's bag

    /**
     * Everything that's mine in one place: homework still to hand in, the latest lessons, my grades,
     * the coming tests, my reading, my stars and certificates.
     *
     * @return array<string, mixed>
     */
    public function bag(Model $me, ChatConversation $conversation): array
    {
        $member = $this->educationMember($me, $conversation);
        $visible = fn ($query) => $this->scoped($query, $me, $conversation, false);

        $assignments = $visible(ChatGroupAssignment::query())->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '>', now()->subDays(7)))->latest('id')->limit(50)->get();
        $handedIn = ChatGroupSubmission::query()->whereIn('assignment_id', $assignments->pluck('id'))->get()
            ->filter(fn (ChatGroupSubmission $s) => in_array($member->id, $s->studentIds(), true))->pluck('assignment_id')->all();

        $grades = ChatGroupGrade::query()->where('participant_id', $member->id)->whereNotNull('graded_at')->latest('graded_at')->limit(30)->get();
        $titles = [
            'assignment' => ChatGroupAssignment::query()->whereIn('id', $grades->where('gradable_type', 'assignment')->pluck('gradable_id'))->get()->keyBy('id'),
            'quiz' => ChatGroupQuiz::query()->whereIn('id', $grades->where('gradable_type', 'quiz')->pluck('gradable_id'))->get()->keyBy('id'),
        ];

        return [
            'to_hand_in' => $assignments->reject(fn ($a) => in_array($a->id, $handedIn, true))
                ->map(fn ($a) => ['id' => $a->uuid, 'title' => $a->title, 'due_at' => $a->due_at?->toIso8601String(), 'is_past_due' => $a->due_at?->isPast() ?? false])->values()->all(),
            'lessons' => $visible(ChatGroupLesson::query())->latest('id')->limit(5)->get()->map(fn ($l) => ['id' => $l->uuid, 'title' => $l->title])->values()->all(),
            'grades' => $grades->map(function (ChatGroupGrade $g) use ($titles) {
                $item = $titles[$g->gradable_type][$g->gradable_id] ?? null;
                if ($item === null || ($g->gradable_type === 'quiz' && ! $item->results_visible)) {
                    return null;
                }

                return ['kind' => $g->gradable_type, 'id' => $item->uuid, 'title' => $item->title, 'score' => $g->score, 'max' => $g->gradable_type === 'assignment' ? $item->max_score : null, 'feedback' => $g->feedback];
            })->filter()->values()->all(),
            'tests' => $this->schedule($me, $conversation)['tests'],
            'reading' => $visible(ChatGroupReadingItem::query())->latest('id')->limit(20)->get()
                ->map(fn ($r) => ['id' => $r->uuid, 'title' => $r->title, 'status' => ChatGroupReadingProgress::query()->where('item_id', $r->id)->where('participant_id', $member->id)->value('status')])
                ->reject(fn ($r) => $r['status'] === 'done')->values()->all(),
            'stars' => ChatGroupStar::query()->where('conversation_id', $conversation->id)->where('participant_id', $member->id)->count(),
            'certificates' => $this->certificates($me, $conversation, $member->id),
        ];
    }

    // ------------------------------------------------------------------ GR-022 the tests calendar

    /** @return array{tests: list<array<string, mixed>>} */
    public function schedule(Model $me, ChatConversation $conversation): array
    {
        $this->educationMember($me, $conversation);

        return ['tests' => $this->scoped(ChatGroupQuiz::query(), $me, $conversation, false)->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->orderByRaw('opens_at is null')->orderBy('opens_at')->limit(30)->get()
            ->map(fn (ChatGroupQuiz $q) => [
                'id' => $q->uuid, 'title' => $q->title, 'opens_at' => $q->opens_at?->toIso8601String(), 'closes_at' => $q->closes_at?->toIso8601String(),
                'duration_minutes' => $q->duration_minutes, 'is_open' => $q->isOpen(), 'is_competition' => $q->is_competition,
            ])->values()->all()];
    }

    /** `chat:remind-group-sessions` also reminds the students of a test opening within the hour, once. */
    public function remindTests(): int
    {
        $count = 0;
        ChatGroupQuiz::query()->where('is_published', true)->whereNull('reminded_at')->whereBetween('opens_at', [now(), now()->addHour()])->limit(200)->get()
            ->each(function (ChatGroupQuiz $q) use (&$count) {
                $q->update(['reminded_at' => now()]);
                $conversation = ChatConversation::query()->with('group')->find($q->conversation_id);
                if ($conversation !== null) {
                    $this->notifier->notify($conversation, GroupStudents::of($conversation, $q->space_id), 'quizzes', 'quiz_soon', ['title' => $q->title], ['quiz_id' => $q->uuid]);
                    $count++;
                }
            });

        return $count;
    }

    // ------------------------------------------------------------------ GR-024 competitions

    /**
     * A class competition's leaderboard — the one place students see each other's points.
     *
     * @return list<array<string, mixed>>
     */
    public function leaderboard(Model $me, ChatConversation $conversation, string $quizUuid): array
    {
        $this->educationMember($me, $conversation);
        $quiz = ChatGroupQuiz::query()->where('conversation_id', $conversation->id)->where('uuid', $quizUuid)->where('is_published', true)->first();

        if ($quiz === null || ! GroupScope::canSee($me, $conversation->id, $quiz->space_id, 'quizzes.manage')) {
            throw new ChatException('quiz_not_found', 404);
        }
        if (! $quiz->is_competition) {
            throw new ChatException('not_a_competition', 422);
        }

        $rank = 0;

        return ChatGroupQuizAttempt::query()->where('quiz_id', $quiz->id)->whereNotNull('submitted_at')->where('needs_marking', false)->get()
            ->sortBy([fn ($a, $b) => $b->total() <=> $a->total(), fn ($a, $b) => $a->submitted_at <=> $b->submitted_at])
            ->map(function (ChatGroupQuizAttempt $a) use ($me, &$rank) {
                return ['rank' => ++$rank, 'student' => $this->person($me, $a->participant_id), 'total' => $a->total()];
            })->values()->all();
    }

    // ------------------------------------------------------------------ GR-025 stars of the week

    /** @return list<array<string, mixed>> this week's stars (or a given week's) */
    public function stars(Model $me, ChatConversation $conversation, ?string $week): array
    {
        $this->educationMember($me, $conversation);
        $monday = ($week ? \Illuminate\Support\Carbon::parse($week) : now())->startOfWeek()->toDateString();

        return ChatGroupStar::query()->where('conversation_id', $conversation->id)->whereDate('week_of', $monday)->get()
            ->map(fn (ChatGroupStar $s) => ['student' => $this->person($me, $s->participant_id), 'reason' => $s->reason, 'week_of' => $s->week_of->toDateString()])
            ->values()->all();
    }

    /**
     * The teacher names a star of the week: a line in the chat for everyone, a notice to the student.
     *
     * @return array<string, mixed>
     */
    public function awardStar(Model $me, ChatConversation $conversation, int $participantId, ?string $reason): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'lessons.manage');
        GroupLifecycleService::assertWritable($conversation);
        $student = GroupStudents::of($conversation)->firstWhere('id', $participantId) ?? throw new ChatException('not_a_student', 422);

        $star = ChatGroupStar::query()->updateOrCreate(
            ['conversation_id' => $conversation->id, 'participant_id' => $student->id, 'week_of' => now()->startOfWeek()->toDateString()],
            ['reason' => $reason, 'awarded_by_participant_id' => $member->id],
        );
        $this->messages->system($conversation, $me, 'star_of_week', [$student->key()], ['reason' => $reason]);
        $this->notifier->notify($conversation, [$student], 'grades', 'star_awarded', ['reason' => (string) $reason]);

        return ['student' => $this->person($me, $student->id), 'reason' => $star->reason, 'week_of' => $star->week_of->toDateString()];
    }

    // ------------------------------------------------------------------ GR-026 reading

    /** @return list<array<string, mixed>> */
    public function reading(Model $me, ChatConversation $conversation): array
    {
        $member = $this->educationMember($me, $conversation);
        $manager = in_array('lessons.manage', $this->perms($member, $conversation), true);

        return $this->scoped(ChatGroupReadingItem::query(), $me, $conversation, $manager)->latest('id')->limit(100)->get()
            ->map(function (ChatGroupReadingItem $r) use ($member, $manager, $conversation) {
                $mine = ChatGroupReadingProgress::query()->where('item_id', $r->id)->where('participant_id', $member->id)->first();
                $students = $manager ? GroupStudents::of($conversation, $r->space_id) : null;

                return [
                    'id' => $r->uuid, 'title' => $r->title, 'author' => $r->author, 'link' => $r->link, 'pages' => $r->pages,
                    'due_at' => $r->due_at?->toIso8601String(),
                    'my_status' => $mine?->status, 'my_pages' => $mine?->pages_read,
                    // Teachers: how the class is doing.
                    'class' => $students === null ? null : [
                        'students' => $students->count(),
                        'reading' => $r->progress()->whereIn('participant_id', $students->pluck('id'))->where('status', 'reading')->count(),
                        'done' => $r->progress()->whereIn('participant_id', $students->pluck('id'))->where('status', 'done')->count(),
                    ],
                ];
            })->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function addReading(Model $me, ChatConversation $conversation, array $data): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'lessons.manage');
        $space = GroupScope::space($conversation, $data['space_id'] ?? null);

        $item = ChatGroupReadingItem::query()->create([
            'conversation_id' => $conversation->id, 'space_id' => $space?->id, 'title' => trim($data['title']),
            'author' => $data['author'] ?? null, 'link' => $data['link'] ?? null, 'pages' => $data['pages'] ?? null,
            'due_at' => $data['due_at'] ?? null, 'created_by_participant_id' => $member->id,
        ]);
        $this->educationChanged($conversation, 'reading', $item->uuid);

        return ['id' => $item->uuid, 'title' => $item->title];
    }

    /** A student's own progress on one item. */
    public function readingProgress(Model $me, ChatConversation $conversation, string $uuid, string $status, ?int $pages, ?string $note): array
    {
        $member = $this->educationMember($me, $conversation);
        $item = ChatGroupReadingItem::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->first();
        if ($item === null || ! GroupScope::canSee($me, $conversation->id, $item->space_id)) {
            throw new ChatException('reading_not_found', 404);
        }

        $row = ChatGroupReadingProgress::query()->updateOrCreate(
            ['item_id' => $item->id, 'participant_id' => $member->id],
            ['status' => $status, 'pages_read' => $pages, 'note' => $note],
        );

        return ['id' => $item->uuid, 'my_status' => $row->status, 'my_pages' => $row->pages_read];
    }

    // ------------------------------------------------------------------ GR-028 certificates

    /**
     * A certificate for a student: a PDF on the private disk, for them and the teachers.
     *
     * @return array<string, mixed>
     */
    public function issueCertificate(Model $me, ChatConversation $conversation, int $participantId, string $title, ?string $reason): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'lessons.manage');
        $student = GroupStudents::of($conversation)->firstWhere('id', $participantId) ?? throw new ChatException('not_a_student', 422);
        $directory = app(ParticipantDirectory::class);

        $certificate = DB::transaction(function () use ($me, $conversation, $member, $student, $title, $reason, $directory) {
            $certificate = ChatGroupCertificate::query()->create([
                'conversation_id' => $conversation->id, 'participant_id' => $student->id, 'title' => trim($title),
                'reason' => $reason, 'issued_by_participant_id' => $member->id,
            ]);
            $pdf = CertificatePdf::render(
                (string) ($student->participant()?->name ?? ''),
                $certificate->title,
                $reason,
                (string) $conversation->group?->name,
                (string) ($directory->profile($me, $member->participant_type, $member->participant_id)['name'] ?? ''),
                now()->toDateString(),
            );
            $certificate->addMediaFromString($pdf)->usingFileName('certificate-'.$certificate->uuid.'.pdf')->usingName($certificate->title.'.pdf')
                ->toMediaCollection(ChatGroupCertificate::PDF, GroupMedia::DISK);
            ChatGroupAuditLog::record($conversation, $me, 'certificate_issued', 'participant:'.$student->id, null, ['title' => $certificate->title]);

            return $certificate;
        });
        $this->notifier->notify($conversation, [$student], 'grades', 'certificate_ready', ['title' => $certificate->title]);

        return $this->presentCertificate($me, $certificate->refresh());
    }

    /**
     * Mine; a teacher's: everyone's (or one student's).
     *
     * @return list<array<string, mixed>>
     */
    public function certificates(Model $me, ChatConversation $conversation, ?int $participantId = null): array
    {
        $member = $this->educationMember($me, $conversation);
        $teacher = in_array('lessons.manage', $this->perms($member, $conversation), true);
        $query = ChatGroupCertificate::query()->where('conversation_id', $conversation->id)->latest('id');
        $teacher ? $query->when($participantId, fn ($q, $id) => $q->where('participant_id', $id)) : $query->where('participant_id', $member->id);

        return $query->limit(100)->with('media')->get()->map(fn ($c) => $this->presentCertificate($me, $c))->values()->all();
    }

    /** @return array<string, mixed> */
    private function presentCertificate(Model $me, ChatGroupCertificate $c): array
    {
        return [
            'id' => $c->uuid, 'title' => $c->title, 'reason' => $c->reason,
            'student' => $this->person($me, $c->participant_id),
            'pdf' => $c->getFirstMedia(ChatGroupCertificate::PDF)?->getUrl(),
            'issued_at' => $c->created_at?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------ GR-030 surveys

    /**
     * A survey: a poll in the chat whose results (and voters) only its maker and the teachers see.
     *
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    public function survey(Model $me, ChatConversation $conversation, string $question, array $options, bool $multiple): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'announcements.post');

        $message = $this->messages->send($me, $conversation, ['type' => 'poll', 'body' => trim($question), 'poll_options' => $options, 'poll_multiple' => $multiple]);
        $message->update(['meta' => array_merge((array) $message->meta, ['survey' => true])]);
        $this->messages->rebroadcast($message->refresh());

        return ['message_id' => $message->uuid];
    }
}
