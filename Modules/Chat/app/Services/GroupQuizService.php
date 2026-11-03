<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupBankQuestion;
use Modules\Chat\Models\ChatGroupGrade;
use Modules\Chat\Models\ChatGroupQuiz;
use Modules\Chat\Models\ChatGroupQuizAttempt;
use Modules\Chat\Models\ChatGroupQuizQuestion;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\Concerns\EducationAccess;
use Modules\Chat\Support\GroupScope;
use Modules\Chat\Support\GroupStudents;

/**
 * Tests and the question bank in educational groups (docs/groups_tasks.md GR-015, GR-016):
 *
 * - The teacher (`quizzes.manage`) builds a test from new questions and/or the bank, for the group
 *   or a term / subgroup, then publishes it with an open window and an optional time limit.
 * - A student (`quizzes.take`) gets one go: choice and true / false are marked at once, written
 *   answers wait for `grading`. The right answers are never sent before the student submits.
 * - A student sees only their own result (when the test shows results, once fully marked); a
 *   classmate's never. Time limit, number of tries, anti-cheating: from the Requirements.
 */
class GroupQuizService
{
    use EducationAccess;

    public const PER_PAGE = 15;

    /** Minutes of grace after the time limit (a slow network). */
    private const GRACE_MINUTES = 2;

    public function __construct(private readonly GroupNotifier $notifier) {}

    /** @return array{quizzes: list<array<string, mixed>>, has_more: bool} */
    public function list(Model $me, ChatConversation $conversation, int $page): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $manager = in_array('quizzes.manage', $perms, true) || in_array('grading', $perms, true);

        $query = $this->scoped(ChatGroupQuiz::query(), $me, $conversation, $manager);
        if (! $manager) {
            $query->where('is_published', true);
        }
        $rows = $query->latest('id')->forPage(max(1, $page), self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();

        return [
            'quizzes' => $rows->take(self::PER_PAGE)->map(fn ($q) => $this->present($member, $perms, $q))->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /** @return array<string, mixed> */
    public function show(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);

        return $this->present($member, $perms, $this->quiz($me, $conversation, $uuid, $perms), detail: true);
    }

    /**
     * @param  array<string, mixed>  $data  questions[]: {type, text, options?, correct?, points?} · bank_ids[]
     * @return array<string, mixed>
     */
    public function create(Model $me, ChatConversation $conversation, array $data): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'quizzes.manage');
        GroupLifecycleService::assertWritable($conversation);
        $space = GroupScope::space($conversation, $data['space_id'] ?? null);

        $quiz = DB::transaction(function () use ($me, $conversation, $member, $data, $space) {
            $quiz = ChatGroupQuiz::query()->create([
                'conversation_id' => $conversation->id,
                'space_id' => $space?->id,
                'title' => trim($data['title']),
                'instructions' => $data['instructions'] ?? null,
                'opens_at' => $data['opens_at'] ?? null,
                'closes_at' => $data['closes_at'] ?? null,
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'results_visible' => (bool) ($data['results_visible'] ?? true),
                'is_competition' => (bool) ($data['is_competition'] ?? false),
                'created_by_participant_id' => $member->id,
            ]);
            $this->addQuestions($quiz, $conversation, (array) ($data['questions'] ?? []), (array) ($data['bank_ids'] ?? []));
            ChatGroupAuditLog::record($conversation, $me, 'quiz_created', 'quiz:'.$quiz->uuid);

            return $quiz;
        });

        return $this->present($member, $perms, $quiz->refresh(), detail: true);
    }

    /**
     * Its details and questions — only before anyone has started it. `is_published` true sends it out.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(Model $me, ChatConversation $conversation, string $uuid, array $data): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'quizzes.manage');
        $quiz = $this->quiz($me, $conversation, $uuid, $perms);
        $started = $quiz->attempts()->exists();

        $changes = array_intersect_key($data, array_flip(['title', 'instructions', 'opens_at', 'closes_at', 'duration_minutes', 'results_visible', 'is_published', 'is_competition']));
        if ($started && (isset($data['questions']) || isset($data['bank_ids']) || isset($data['duration_minutes']))) {
            throw new ChatException('quiz_already_started', 409);
        }
        $publishing = ! empty($changes['is_published']) && ! $quiz->is_published;
        if ($publishing && ! $quiz->questions()->exists() && empty($data['questions']) && empty($data['bank_ids'])) {
            throw new ChatException('quiz_no_questions', 422);
        }

        DB::transaction(function () use ($me, $conversation, $quiz, $changes, $data) {
            $quiz->update($changes);
            if (isset($data['questions']) || isset($data['bank_ids'])) {
                $quiz->questions()->delete();
                $this->addQuestions($quiz, $conversation, (array) ($data['questions'] ?? []), (array) ($data['bank_ids'] ?? []));
            }
            ChatGroupAuditLog::record($conversation, $me, 'quiz_updated', 'quiz:'.$quiz->uuid);
        });

        if ($publishing) {
            $this->educationChanged($conversation, 'quiz', $quiz->uuid);
            $this->notifier->notify($conversation, GroupStudents::of($conversation, $quiz->space_id), 'quizzes', 'quiz_new', ['title' => $quiz->title], ['quiz_id' => $quiz->uuid]);
        }

        return $this->present($member, $perms, $quiz->refresh(), detail: true);
    }

    public function delete(Model $me, ChatConversation $conversation, string $uuid): void
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'quizzes.manage');
        $quiz = $this->quiz($me, $conversation, $uuid, $perms);
        ChatGroupAuditLog::record($conversation, $me, 'quiz_deleted', 'quiz:'.$quiz->uuid, ['title' => $quiz->title]);
        ChatGroupGrade::query()->where('gradable_type', 'quiz')->where('gradable_id', $quiz->id)->delete();
        $quiz->delete();
        $this->educationChanged($conversation, 'quiz', $uuid);
    }

    /**
     * A student starts their one go (the clock starts); the questions come without the answers.
     *
     * @return array<string, mixed>
     */
    public function start(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'quizzes.take');
        $quiz = $this->quiz($me, $conversation, $uuid, $perms);

        $attempt = ChatGroupQuizAttempt::query()->where('quiz_id', $quiz->id)->where('participant_id', $member->id)->first();
        if ($attempt?->submitted_at !== null) {
            throw new ChatException('quiz_already_taken', 409);
        }
        if (! $quiz->isOpen()) {
            throw new ChatException('quiz_not_open', 422);
        }
        $attempt ??= ChatGroupQuizAttempt::query()->create(['quiz_id' => $quiz->id, 'participant_id' => $member->id, 'started_at' => now()]);

        return $this->present($member, $perms, $quiz, detail: true) + ['started_at' => $attempt->started_at->toIso8601String()];
    }

    /**
     * A student hands in: choice / true-false marked now, written answers wait for a marker.
     *
     * @param  array<int|string, mixed>  $answers  question id => answer (choice: list of indexes · true_false: bool · essay: text)
     * @return array<string, mixed>
     */
    public function submit(Model $me, ChatConversation $conversation, string $uuid, array $answers): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'quizzes.take');
        $quiz = $this->quiz($me, $conversation, $uuid, $perms);

        $attempt = ChatGroupQuizAttempt::query()->where('quiz_id', $quiz->id)->where('participant_id', $member->id)->first()
            ?? throw new ChatException('quiz_not_started', 422);
        if ($attempt->submitted_at !== null) {
            throw new ChatException('quiz_already_taken', 409);
        }
        if ($quiz->duration_minutes !== null && $attempt->started_at->copy()->addMinutes($quiz->duration_minutes + self::GRACE_MINUTES)->isPast()) {
            throw new ChatException('quiz_time_over', 422);
        }
        if ($quiz->closes_at !== null && $quiz->closes_at->copy()->addMinutes(self::GRACE_MINUTES)->isPast()) {
            throw new ChatException('quiz_not_open', 422);
        }

        $questions = $quiz->questions;
        $marks = [];
        $pending = false;
        $kept = [];
        foreach ($questions as $q) {
            $answer = $answers[$q->id] ?? $answers[(string) $q->id] ?? null;
            $kept[$q->id] = $answer;
            if ($q->type === 'essay') {
                $pending = $pending || filled($answer);
                $marks[$q->id] = filled($answer) ? null : 0;
            } else {
                $marks[$q->id] = $this->isRight($q, $answer) ? (float) $q->points : 0.0;
            }
        }

        $attempt->update(['answers' => $kept, 'marks' => $marks, 'submitted_at' => now(), 'needs_marking' => $pending]);
        if (! $pending) {
            $this->saveGrade($quiz, $attempt, null);
        }
        $this->educationChanged($conversation, 'quiz', $quiz->uuid);

        return $this->present($member, $perms, $quiz->refresh(), detail: true);
    }

    /**
     * Every student's go, for whoever marks.
     *
     * @return list<array<string, mixed>>
     */
    public function attempts(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'grading');
        $quiz = $this->quiz($me, $conversation, $uuid, $perms);

        return $quiz->attempts()->whereNotNull('submitted_at')->latest('submitted_at')->get()->map(fn (ChatGroupQuizAttempt $a) => [
            'id' => $a->id,
            'student' => $this->person($me, $a->participant_id),
            'answers' => $a->answers,
            'marks' => $a->marks,
            'total' => $a->total(),
            'needs_marking' => $a->needs_marking,
            'submitted_at' => $a->submitted_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * A marker gives points for written answers (or corrects any). Sensitive: on the activity log.
     *
     * @param  array<int|string, float|int>  $marks  question id => points
     * @return array<string, mixed>
     */
    public function mark(Model $me, ChatConversation $conversation, string $uuid, int $attemptId, array $marks, ?string $feedback): array
    {
        $member = $this->educationMember($me, $conversation);
        $perms = $this->perms($member, $conversation);
        $this->need($perms, 'grading');
        $quiz = $this->quiz($me, $conversation, $uuid, $perms);
        $attempt = $quiz->attempts()->whereNotNull('submitted_at')->find($attemptId) ?? throw new ChatException('attempt_not_found', 404);

        $questions = $quiz->questions->keyBy('id');
        $current = (array) $attempt->marks;
        foreach ($marks as $qid => $points) {
            $q = $questions->get((int) $qid) ?? throw new ChatException('question_not_found', 404);
            $current[$q->id] = max(0, min((float) $q->points, (float) $points));
        }
        $before = $attempt->marks;
        $attempt->update(['marks' => $current, 'needs_marking' => collect($current)->contains(fn ($v) => $v === null)]);
        ChatGroupAuditLog::record($conversation, $me, 'quiz_marked', 'participant:'.$attempt->participant_id, ['marks' => $before], ['quiz' => $quiz->uuid, 'marks' => $current]);

        if (! $attempt->needs_marking) {
            $this->saveGrade($quiz, $attempt, $member, $feedback);
            $student = ChatParticipant::query()->find($attempt->participant_id);
            if ($student !== null) {
                $this->notifier->notify($conversation, [$student], 'grades', 'grade_ready', ['title' => $quiz->title], ['quiz_id' => $quiz->uuid]);
            }
        }

        return ['id' => $attempt->id, 'marks' => $attempt->marks, 'total' => $attempt->total(), 'needs_marking' => $attempt->needs_marking];
    }

    // ------------------------------------------------------------------ GR-016 the question bank

    /** @return list<array<string, mixed>> */
    public function bank(Model $me, ChatConversation $conversation, array $filters): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'quizzes.manage');

        return ChatGroupBankQuestion::query()->where('conversation_id', $conversation->id)
            ->when($filters['subject'] ?? null, fn ($q, $v) => $q->where('subject', $v))
            ->when($filters['unit'] ?? null, fn ($q, $v) => $q->where('unit', $v))
            ->when($filters['difficulty'] ?? null, fn ($q, $v) => $q->where('difficulty', $v))
            ->latest('id')->limit(200)->get()
            ->map(fn (ChatGroupBankQuestion $b) => $b->only(['id', 'subject', 'unit', 'difficulty', 'type', 'text', 'options', 'correct', 'points']))->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function addToBank(Model $me, ChatConversation $conversation, array $data): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'quizzes.manage');
        $this->assertQuestion($data);

        $row = ChatGroupBankQuestion::query()->create([
            'conversation_id' => $conversation->id,
            'created_by_participant_id' => $member->id,
            'correct' => $this->normalizeCorrect($data),
        ] + array_intersect_key($data, array_flip(['subject', 'unit', 'difficulty', 'type', 'text', 'options', 'points'])));

        return $row->only(['id', 'subject', 'unit', 'difficulty', 'type', 'text', 'options', 'correct', 'points']);
    }

    public function removeFromBank(Model $me, ChatConversation $conversation, int $id): void
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'quizzes.manage');
        ChatGroupBankQuestion::query()->where('conversation_id', $conversation->id)->whereKey($id)->delete();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  list<int>  $bankIds
     */
    private function addQuestions(ChatGroupQuiz $quiz, ChatConversation $conversation, array $questions, array $bankIds): void
    {
        $order = 0;
        foreach ($questions as $q) {
            $this->assertQuestion($q);
            ChatGroupQuizQuestion::query()->create([
                'quiz_id' => $quiz->id, 'type' => $q['type'], 'text' => trim($q['text']), 'options' => $q['options'] ?? null,
                'correct' => $this->normalizeCorrect($q), 'points' => $q['points'] ?? 1, 'sort_order' => $order++,
            ]);
        }
        foreach (ChatGroupBankQuestion::query()->where('conversation_id', $conversation->id)->whereIn('id', $bankIds)->get() as $b) {
            ChatGroupQuizQuestion::query()->create([
                'quiz_id' => $quiz->id, 'type' => $b->type, 'text' => $b->text, 'options' => $b->options,
                'correct' => $b->correct, 'points' => $b->points, 'sort_order' => $order++,
            ]);
        }
    }

    /** @param array<string, mixed> $q */
    private function assertQuestion(array $q): void
    {
        $type = $q['type'] ?? null;
        if (! in_array($type, ChatGroupQuizQuestion::TYPES, true) || blank($q['text'] ?? null)) {
            throw new ChatException('question_invalid', 422);
        }
        if ($type === 'choice') {
            $options = (array) ($q['options'] ?? []);
            $correct = array_map('intval', (array) ($q['correct'] ?? []));
            if (count($options) < 2 || $correct === [] || max($correct) >= count($options) || min($correct) < 0) {
                throw new ChatException('question_invalid', 422);
            }
        }
        if ($type === 'true_false' && ! is_bool($q['correct'] ?? null) && ! in_array($q['correct'] ?? null, [0, 1, '0', '1', 'true', 'false'], true)) {
            throw new ChatException('question_invalid', 422);
        }
    }

    /** @param array<string, mixed> $q */
    private function normalizeCorrect(array $q): ?array
    {
        return match ($q['type']) {
            'choice' => array_values(array_unique(array_map('intval', (array) $q['correct']))),
            'true_false' => [filter_var($q['correct'], FILTER_VALIDATE_BOOLEAN)],
            default => null,
        };
    }

    private function isRight(ChatGroupQuizQuestion $q, mixed $answer): bool
    {
        if ($answer === null) {
            return false;
        }

        return match ($q->type) {
            'choice' => collect((array) $answer)->map(fn ($v) => (int) $v)->unique()->sort()->values()->all() === collect((array) $q->correct)->map(fn ($v) => (int) $v)->sort()->values()->all(),
            'true_false' => filter_var($answer, FILTER_VALIDATE_BOOLEAN) === (bool) ((array) $q->correct)[0],
            default => false,
        };
    }

    private function saveGrade(ChatGroupQuiz $quiz, ChatGroupQuizAttempt $attempt, ?ChatParticipant $by, ?string $feedback = null): void
    {
        ChatGroupGrade::query()->updateOrCreate(
            ['gradable_type' => 'quiz', 'gradable_id' => $quiz->id, 'participant_id' => $attempt->participant_id],
            ['score' => $attempt->total(), 'feedback' => $feedback, 'graded_by_participant_id' => $by?->id, 'graded_at' => now()],
        );
    }

    /** @param list<string> $perms */
    private function quiz(Model $me, ChatConversation $conversation, string $uuid, array $perms): ChatGroupQuiz
    {
        $quiz = ChatGroupQuiz::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->with('questions')->first();
        $manager = in_array('quizzes.manage', $perms, true) || in_array('grading', $perms, true);

        if ($quiz === null || ! GroupScope::canSee($me, $conversation->id, $quiz->space_id, 'quizzes.manage') || (! $manager && ! $quiz->is_published)) {
            throw new ChatException('quiz_not_found', 404);
        }

        return $quiz;
    }

    /**
     * The test as this viewer may see it. Right answers: only to its managers, and to a student
     * after handing in when results are shown and fully marked.
     *
     * @param  list<string>  $perms
     * @return array<string, mixed>
     */
    private function present(ChatParticipant $member, array $perms, ChatGroupQuiz $quiz, bool $detail = false): array
    {
        $manager = in_array('quizzes.manage', $perms, true);
        $mine = ChatGroupQuizAttempt::query()->where('quiz_id', $quiz->id)->where('participant_id', $member->id)->first();
        $done = $mine?->submitted_at !== null;
        $showResult = $done && $quiz->results_visible && ! $mine->needs_marking;
        $showQuestions = $manager || ($mine !== null && ! $done && $quiz->isOpen()) || $showResult;

        return [
            'id' => $quiz->uuid,
            'title' => $quiz->title,
            'instructions' => $quiz->instructions,
            'opens_at' => $quiz->opens_at?->toIso8601String(),
            'closes_at' => $quiz->closes_at?->toIso8601String(),
            'duration_minutes' => $quiz->duration_minutes,
            'is_published' => $quiz->is_published,
            'is_open' => $quiz->isOpen(),
            'results_visible' => $quiz->results_visible,
            'is_competition' => (bool) $quiz->is_competition,
            'questions_count' => $quiz->questions->count(),
            'total_points' => (float) $quiz->questions->sum('points'),
            'questions' => $detail && $showQuestions ? $quiz->questions->map(fn (ChatGroupQuizQuestion $q) => [
                'id' => $q->id, 'type' => $q->type, 'text' => $q->text, 'options' => $q->options, 'points' => $q->points,
                'correct' => $manager || $showResult ? $q->correct : null,
            ])->values()->all() : null,
            // Mine only.
            'my_attempt' => $mine === null ? null : [
                'started_at' => $mine->started_at?->toIso8601String(),
                'submitted_at' => $mine->submitted_at?->toIso8601String(),
                'waiting_for_marking' => $done && $mine->needs_marking,
                'answers' => $done ? $mine->answers : null,
                'marks' => $showResult ? $mine->marks : null,
                'total' => $showResult ? $mine->total() : null,
            ],
            'can' => ['manage' => $manager, 'take' => in_array('quizzes.take', $perms, true), 'grade' => in_array('grading', $perms, true)],
            'attempts_count' => $manager || in_array('grading', $perms, true) ? $quiz->attempts()->whereNotNull('submitted_at')->count() : null,
        ];
    }
}
