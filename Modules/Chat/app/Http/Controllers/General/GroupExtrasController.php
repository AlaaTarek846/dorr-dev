<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\GroupClassExtrasService;
use Modules\Chat\Services\GroupHelpService;

/**
 * Educational group extras (GR-021, 022, 024, 025, 026, 028, 030) and help requests in both kinds
 * (GR-031, GR-041). Who may do what is checked in the services.
 */
class GroupExtrasController extends Controller
{
    public function __construct(
        private readonly GroupClassExtrasService $extras,
        private readonly GroupHelpService $help,
    ) {}

    /** GET groups/{c}/bag — the student's bag. */
    public function bag(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->extras->bag($request->user(), $conversation), __('api.retrieved'));
    }

    /** GET groups/{c}/quizzes/schedule — the coming tests. */
    public function schedule(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->extras->schedule($request->user(), $conversation), __('api.retrieved'));
    }

    /** GET groups/{c}/quizzes/{q}/leaderboard — a class competition only. */
    public function leaderboard(Request $request, ChatConversation $conversation, string $quiz)
    {
        return ApiResponse::success($this->extras->leaderboard($request->user(), $conversation, $quiz), __('api.retrieved'));
    }

    public function stars(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['week' => ['nullable', 'date']]);

        return ApiResponse::success($this->extras->stars($request->user(), $conversation, $data['week'] ?? null), __('api.retrieved'));
    }

    /** POST groups/{c}/stars `{participant_id, reason?}` — this week's star. */
    public function awardStar(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['participant_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:300']]);

        return ApiResponse::created($this->extras->awardStar($request->user(), $conversation, (int) $data['participant_id'], $data['reason'] ?? null), __('api.created'));
    }

    public function reading(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->extras->reading($request->user(), $conversation), __('api.retrieved'));
    }

    public function addReading(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'author' => ['nullable', 'string', 'max:150'],
            'link' => ['nullable', 'url', 'max:500'],
            'pages' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'due_at' => ['nullable', 'date'],
            'space_id' => ['nullable', 'integer'],
        ]);

        return ApiResponse::created($this->extras->addReading($request->user(), $conversation, $data), __('api.created'));
    }

    /** POST groups/{c}/reading/{item}/progress `{status: reading|done, pages_read?, note?}` — my own. */
    public function readingProgress(Request $request, ChatConversation $conversation, string $item)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['reading', 'done'])],
            'pages_read' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::success($this->extras->readingProgress($request->user(), $conversation, $item, $data['status'], $data['pages_read'] ?? null, $data['note'] ?? null), __('api.updated'));
    }

    public function certificates(Request $request, ChatConversation $conversation)
    {
        $id = $request->query('participant_id');

        return ApiResponse::success($this->extras->certificates($request->user(), $conversation, $id !== null ? (int) $id : null), __('api.retrieved'));
    }

    /** POST groups/{c}/certificates `{participant_id, title, reason?}` — a PDF for the student. */
    public function issueCertificate(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['participant_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:200'], 'reason' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::created($this->extras->issueCertificate($request->user(), $conversation, (int) $data['participant_id'], $data['title'], $data['reason'] ?? null), __('api.created'));
    }

    /** POST groups/{c}/surveys `{question, options[], multiple?}` — results for its maker and the managers. */
    public function survey(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
            'options' => ['required', 'array', 'min:2', 'max:12'],
            'options.*' => ['required', 'string', 'max:100'],
            'multiple' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::created($this->extras->survey($request->user(), $conversation, $data['question'], $data['options'], (bool) ($data['multiple'] ?? false)), __('api.created'));
    }

    // ------------------------------------------------------------------ help requests

    public function helpRequests(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'closed'])], 'page' => ['nullable', 'integer', 'min:1']]);

        return ApiResponse::success($this->help->list($request->user(), $conversation, $data['status'] ?? 'open', (int) ($data['page'] ?? 1)), __('api.retrieved'));
    }

    /** POST groups/{c}/help `{question, to_participant_id?}`. */
    public function ask(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:2000'], 'to_participant_id' => ['nullable', 'integer']]);

        return ApiResponse::created($this->help->ask($request->user(), $conversation, $data['question'], isset($data['to_participant_id']) ? (int) $data['to_participant_id'] : null), __('api.created'));
    }

    public function answer(Request $request, ChatConversation $conversation, string $help)
    {
        $data = $request->validate(['answer' => ['required', 'string', 'max:5000']]);

        return ApiResponse::success($this->help->answer($request->user(), $conversation, $help, $data['answer']), __('api.updated'));
    }

    public function closeHelp(Request $request, ChatConversation $conversation, string $help)
    {
        return ApiResponse::success($this->help->close($request->user(), $conversation, $help), __('api.updated'));
    }
}
