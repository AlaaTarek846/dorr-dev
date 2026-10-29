<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAttendance;
use Modules\Chat\Models\ChatGroupQuizQuestion;
use Modules\Chat\Services\GroupAssignmentService;
use Modules\Chat\Services\GroupAttendanceService;
use Modules\Chat\Services\GroupLessonService;
use Modules\Chat\Services\GroupQuizService;

/**
 * Educational groups (docs/groups_tasks.md GR-013..017): lessons, homework, tests and the question
 * bank, sessions and attendance. `{conversation}` is the main group; items are given by uuid
 * (bank questions and attempts by id). Who may do what is checked in the services.
 */
class GroupEducationController extends Controller
{
    public function __construct(
        private readonly GroupLessonService $lessons,
        private readonly GroupAssignmentService $assignments,
        private readonly GroupQuizService $quizzes,
        private readonly GroupAttendanceService $attendance,
    ) {}

    // ------------------------------------------------------------------ GR-013 lessons

    public function lessons(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'space_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);

        return ApiResponse::success($this->lessons->list($request->user(), $conversation, $data['q'] ?? null, isset($data['space_id']) ? (int) $data['space_id'] : null, (int) ($data['page'] ?? 1)), __('api.retrieved'));
    }

    public function lesson(Request $request, ChatConversation $conversation, string $lesson)
    {
        return ApiResponse::success($this->lessons->show($request->user(), $conversation, $lesson), __('api.retrieved'));
    }

    /** POST groups/{c}/lessons — multipart `title`, `body?`, `space_id?`, `files[]`. */
    public function storeLesson(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'space_id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:102400'],
        ]);

        return ApiResponse::created($this->lessons->create($request->user(), $conversation, $data, (array) $request->file('files', [])), __('api.created'));
    }

    /** POST groups/{c}/lessons/{lesson} — multipart, like store (+ `remove_files[]`). */
    public function updateLesson(Request $request, ChatConversation $conversation, string $lesson)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'space_id' => ['sometimes', 'nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:102400'],
            'remove_files' => ['nullable', 'array'],
            'remove_files.*' => ['uuid'],
        ]);

        return ApiResponse::success($this->lessons->update($request->user(), $conversation, $lesson, $data, (array) $request->file('files', [])), __('api.updated'));
    }

    public function destroyLesson(Request $request, ChatConversation $conversation, string $lesson)
    {
        $this->lessons->delete($request->user(), $conversation, $lesson);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST groups/{c}/lessons/{lesson}/messages `{message_id}` — a chat message (and its files) into the lesson. */
    public function lessonMessage(Request $request, ChatConversation $conversation, string $lesson)
    {
        $data = $request->validate(['message_id' => ['required', 'uuid']]);

        return ApiResponse::success($this->lessons->addMessage($request->user(), $conversation, $lesson, $data['message_id']), __('api.updated'));
    }

    // ------------------------------------------------------------------ GR-014 homework

    public function assignments(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->assignments->list($request->user(), $conversation, (int) $request->query('page', 1)), __('api.retrieved'));
    }

    public function assignment(Request $request, ChatConversation $conversation, string $assignment)
    {
        return ApiResponse::success($this->assignments->show($request->user(), $conversation, $assignment), __('api.retrieved'));
    }

    public function storeAssignment(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
            'mode' => ['nullable', Rule::in(['individual', 'team'])],
            'max_score' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'space_id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:102400'],
        ]);

        return ApiResponse::created($this->assignments->create($request->user(), $conversation, $data, (array) $request->file('files', [])), __('api.created'));
    }

    public function updateAssignment(Request $request, ChatConversation $conversation, string $assignment)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'max_score' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:102400'],
        ]);

        return ApiResponse::success($this->assignments->update($request->user(), $conversation, $assignment, $data, (array) $request->file('files', [])), __('api.updated'));
    }

    public function destroyAssignment(Request $request, ChatConversation $conversation, string $assignment)
    {
        $this->assignments->delete($request->user(), $conversation, $assignment);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST groups/{c}/assignments/{a}/submit — multipart `body?`, `files[]`, `team[]` (team mode). */
    public function submit(Request $request, ChatConversation $conversation, string $assignment)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:20000'],
            'files' => ['nullable', 'array', 'max:'.GroupAssignmentService::MAX_FILES],
            'files.*' => ['file', 'max:102400'],
            'team' => ['nullable', 'array', 'max:20'],
            'team.*' => ['integer'],
        ]);

        return ApiResponse::success($this->assignments->submit(
            $request->user(), $conversation, $assignment, $data['body'] ?? null, (array) $request->file('files', []), array_map('intval', $data['team'] ?? []),
        ), __('api.updated'));
    }

    public function submissions(Request $request, ChatConversation $conversation, string $assignment)
    {
        return ApiResponse::success($this->assignments->submissions($request->user(), $conversation, $assignment), __('api.retrieved'));
    }

    /** POST groups/{c}/assignments/{a}/grades `{participant_id, score?, feedback?}`. */
    public function grade(Request $request, ChatConversation $conversation, string $assignment)
    {
        $data = $request->validate([
            'participant_id' => ['required', 'integer'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success($this->assignments->grade(
            $request->user(), $conversation, $assignment, (int) $data['participant_id'], isset($data['score']) ? (float) $data['score'] : null, $data['feedback'] ?? null,
        ), __('api.updated'));
    }

    // ------------------------------------------------------------------ GR-015 tests · GR-016 bank

    public function quizzes(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->quizzes->list($request->user(), $conversation, (int) $request->query('page', 1)), __('api.retrieved'));
    }

    public function quiz(Request $request, ChatConversation $conversation, string $quiz)
    {
        return ApiResponse::success($this->quizzes->show($request->user(), $conversation, $quiz), __('api.retrieved'));
    }

    public function storeQuiz(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::created($this->quizzes->create($request->user(), $conversation, $this->quizData($request, true)), __('api.created'));
    }

    public function updateQuiz(Request $request, ChatConversation $conversation, string $quiz)
    {
        return ApiResponse::success($this->quizzes->update($request->user(), $conversation, $quiz, $this->quizData($request, false)), __('api.updated'));
    }

    public function destroyQuiz(Request $request, ChatConversation $conversation, string $quiz)
    {
        $this->quizzes->delete($request->user(), $conversation, $quiz);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function startQuiz(Request $request, ChatConversation $conversation, string $quiz)
    {
        return ApiResponse::success($this->quizzes->start($request->user(), $conversation, $quiz), __('api.updated'));
    }

    /** POST groups/{c}/quizzes/{q}/submit `{answers: {question_id: answer}}`. */
    public function submitQuiz(Request $request, ChatConversation $conversation, string $quiz)
    {
        $data = $request->validate(['answers' => ['present', 'array']]);

        return ApiResponse::success($this->quizzes->submit($request->user(), $conversation, $quiz, $data['answers']), __('api.updated'));
    }

    public function attempts(Request $request, ChatConversation $conversation, string $quiz)
    {
        return ApiResponse::success($this->quizzes->attempts($request->user(), $conversation, $quiz), __('api.retrieved'));
    }

    /** POST groups/{c}/quizzes/{q}/attempts/{attempt}/mark `{marks: {question_id: points}, feedback?}`. */
    public function markAttempt(Request $request, ChatConversation $conversation, string $quiz, int $attempt)
    {
        $data = $request->validate(['marks' => ['required', 'array'], 'marks.*' => ['numeric', 'min:0'], 'feedback' => ['nullable', 'string', 'max:2000']]);

        return ApiResponse::success($this->quizzes->mark($request->user(), $conversation, $quiz, $attempt, $data['marks'], $data['feedback'] ?? null), __('api.updated'));
    }

    public function bank(Request $request, ChatConversation $conversation)
    {
        $filters = $request->validate(['subject' => ['nullable', 'string'], 'unit' => ['nullable', 'string'], 'difficulty' => ['nullable', Rule::in(['easy', 'medium', 'hard'])]]);

        return ApiResponse::success($this->quizzes->bank($request->user(), $conversation, $filters), __('api.retrieved'));
    }

    public function storeBank(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:100'],
            'difficulty' => ['nullable', Rule::in(['easy', 'medium', 'hard'])],
            'type' => ['required', Rule::in(ChatGroupQuizQuestion::TYPES)],
            'text' => ['required', 'string', 'max:5000'],
            'options' => ['nullable', 'array', 'max:10'],
            'options.*' => ['string', 'max:500'],
            'correct' => ['nullable'],
            'points' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return ApiResponse::created($this->quizzes->addToBank($request->user(), $conversation, $data), __('api.created'));
    }

    public function destroyBank(Request $request, ChatConversation $conversation, int $question)
    {
        $this->quizzes->removeFromBank($request->user(), $conversation, $question);

        return ApiResponse::success(null, __('api.deleted'));
    }

    // ------------------------------------------------------------------ GR-017 sessions and attendance

    public function sessions(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['when' => ['nullable', Rule::in(['upcoming', 'past'])], 'page' => ['nullable', 'integer', 'min:1']]);

        return ApiResponse::success($this->attendance->list($request->user(), $conversation, $data['when'] ?? 'upcoming', (int) ($data['page'] ?? 1)), __('api.retrieved'));
    }

    public function session(Request $request, ChatConversation $conversation, string $session)
    {
        return ApiResponse::success($this->attendance->show($request->user(), $conversation, $session), __('api.retrieved'));
    }

    public function storeSession(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'space_id' => ['nullable', 'integer'],
        ]);

        return ApiResponse::created($this->attendance->create($request->user(), $conversation, $data), __('api.created'));
    }

    public function destroySession(Request $request, ChatConversation $conversation, string $session)
    {
        $this->attendance->delete($request->user(), $conversation, $session);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST groups/{c}/sessions/{s}/attendance `{entries: [{participant_id, status, note?}]}`. */
    public function recordAttendance(Request $request, ChatConversation $conversation, string $session)
    {
        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:1000'],
            'entries.*.participant_id' => ['required', 'integer'],
            'entries.*.status' => ['required', Rule::in(ChatGroupAttendance::STATUSES)],
            'entries.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        return ApiResponse::success($this->attendance->record($request->user(), $conversation, $session, $data['entries']), __('api.updated'));
    }

    /** GET groups/{c}/attendance?participant_id= — mine, or (attendance.manage) a student's. */
    public function attendanceReport(Request $request, ChatConversation $conversation)
    {
        $id = $request->query('participant_id');

        return ApiResponse::success($this->attendance->report($request->user(), $conversation, $id !== null ? (int) $id : null), __('api.retrieved'));
    }

    /** @return array<string, mixed> */
    private function quizData(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'opens_at' => ['sometimes', 'nullable', 'date'],
            'closes_at' => ['sometimes', 'nullable', 'date'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'results_visible' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            // GR-024: a class competition (a leaderboard every student sees).
            'is_competition' => ['sometimes', 'boolean'],
            'space_id' => ['sometimes', 'nullable', 'integer'],
            'questions' => ['sometimes', 'array', 'max:200'],
            'questions.*.type' => ['required', Rule::in(ChatGroupQuizQuestion::TYPES)],
            'questions.*.text' => ['required', 'string', 'max:5000'],
            'questions.*.options' => ['nullable', 'array', 'max:10'],
            'questions.*.options.*' => ['string', 'max:500'],
            'questions.*.correct' => ['nullable'],
            'questions.*.points' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bank_ids' => ['sometimes', 'array', 'max:200'],
            'bank_ids.*' => ['integer'],
        ]);
    }
}
