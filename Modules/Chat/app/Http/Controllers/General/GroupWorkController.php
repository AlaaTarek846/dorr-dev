<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupIdea;
use Modules\Chat\Services\GroupPollService;
use Modules\Chat\Services\GroupWorkExtrasService;

/**
 * Work group extras (GR-040, 042, 043, 044, 046) and anonymous polls in both kinds (GR-045). Who may
 * do what is checked in the services.
 */
class GroupWorkController extends Controller
{
    public function __construct(
        private readonly GroupWorkExtrasService $work,
        private readonly GroupPollService $polls,
    ) {}

    // ------------------------------------------------------------------ shifts

    /** GET groups/{c}/shifts?from=YYYY-MM-DD&mine=1 — a week from that day. */
    public function shifts(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'mine' => ['nullable', 'boolean']]);

        return ApiResponse::success($this->work->shifts($request->user(), $conversation, $data['from'] ?? null, (bool) ($data['mine'] ?? false)), __('api.retrieved'));
    }

    public function storeShift(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'participant_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        return ApiResponse::created($this->work->createShift($request->user(), $conversation, $data), __('api.created'));
    }

    public function destroyShift(Request $request, ChatConversation $conversation, string $shift)
    {
        $this->work->deleteShift($request->user(), $conversation, $shift);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function handover(Request $request, ChatConversation $conversation, string $shift)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:3000']]);

        return ApiResponse::success($this->work->handover($request->user(), $conversation, $shift, $data['note']), __('api.updated'));
    }

    /** POST groups/{c}/shifts/{s}/swap `{with_participant_id|null}`. */
    public function askSwap(Request $request, ChatConversation $conversation, string $shift)
    {
        $data = $request->validate(['with_participant_id' => ['nullable', 'integer']]);

        return ApiResponse::success($this->work->askSwap($request->user(), $conversation, $shift, isset($data['with_participant_id']) ? (int) $data['with_participant_id'] : null), __('api.updated'));
    }

    /** POST groups/{c}/shifts/{s}/swap/answer `{accept}`. */
    public function answerSwap(Request $request, ChatConversation $conversation, string $shift)
    {
        $data = $request->validate(['accept' => ['required', 'boolean']]);

        return ApiResponse::success($this->work->answerSwap($request->user(), $conversation, $shift, (bool) $data['accept']), __('api.updated'));
    }

    // ------------------------------------------------------------------ competitions and badges

    public function goals(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->work->goals($request->user(), $conversation), __('api.retrieved'));
    }

    public function storeGoal(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'ends_at' => ['nullable', 'date']]);

        return ApiResponse::created($this->work->createGoal($request->user(), $conversation, $data), __('api.created'));
    }

    /** POST groups/{c}/goals/{g}/points `{participant_id, points, note?}`. */
    public function points(Request $request, ChatConversation $conversation, string $goal)
    {
        $data = $request->validate(['participant_id' => ['required', 'integer'], 'points' => ['required', 'integer', 'between:-1000,1000'], 'note' => ['nullable', 'string', 'max:300']]);

        return ApiResponse::success($this->work->givePoints($request->user(), $conversation, $goal, (int) $data['participant_id'], (int) $data['points'], $data['note'] ?? null), __('api.updated'));
    }

    public function badges(Request $request, ChatConversation $conversation)
    {
        $id = $request->query('participant_id');

        return ApiResponse::success($this->work->badges($request->user(), $conversation, $id !== null ? (int) $id : null), __('api.retrieved'));
    }

    public function giveBadge(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['participant_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:100'], 'emoji' => ['nullable', 'string', 'max:16'], 'reason' => ['nullable', 'string', 'max:300']]);

        return ApiResponse::created($this->work->giveBadge($request->user(), $conversation, (int) $data['participant_id'], $data['title'], $data['emoji'] ?? null, $data['reason'] ?? null), __('api.created'));
    }

    // ------------------------------------------------------------------ ideas

    public function ideas(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(ChatGroupIdea::STATUSES)]]);

        return ApiResponse::success($this->work->ideas($request->user(), $conversation, $data['status'] ?? null), __('api.retrieved'));
    }

    public function propose(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'body' => ['nullable', 'string', 'max:5000']]);

        return ApiResponse::created($this->work->propose($request->user(), $conversation, $data['title'], $data['body'] ?? null), __('api.created'));
    }

    public function vote(Request $request, ChatConversation $conversation, string $idea)
    {
        return ApiResponse::success($this->work->vote($request->user(), $conversation, $idea), __('api.updated'));
    }

    public function ideaStatus(Request $request, ChatConversation $conversation, string $idea)
    {
        $data = $request->validate(['status' => ['required', Rule::in(ChatGroupIdea::STATUSES)], 'note' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success($this->work->setIdeaStatus($request->user(), $conversation, $idea, $data['status'], $data['note'] ?? null), __('api.updated'));
    }

    // ------------------------------------------------------------------ meetings

    public function meetings(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['when' => ['nullable', Rule::in(['upcoming', 'past'])]]);

        return ApiResponse::success($this->work->meetings($request->user(), $conversation, $data['when'] ?? 'upcoming'), __('api.retrieved'));
    }

    public function storeMeeting(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'starts_at' => ['required', 'date'], 'agenda' => ['nullable', 'string', 'max:5000'], 'space_id' => ['nullable', 'integer']]);

        return ApiResponse::created($this->work->createMeeting($request->user(), $conversation, $data), __('api.created'));
    }

    /** POST groups/{c}/meetings/{m}/minutes `{agenda?, minutes?}`. */
    public function minutes(Request $request, ChatConversation $conversation, string $meeting)
    {
        $data = $request->validate(['agenda' => ['nullable', 'string', 'max:5000'], 'minutes' => ['nullable', 'string', 'max:20000']]);

        return ApiResponse::success($this->work->saveMinutes($request->user(), $conversation, $meeting, $data['agenda'] ?? null, $data['minutes'] ?? null), __('api.updated'));
    }

    /** POST groups/{c}/meetings/{m}/tasks `{items: [{title, assignee_participant_id?, due_at?}]}`. */
    public function meetingTasks(Request $request, ChatConversation $conversation, string $meeting)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.assignee_participant_id' => ['nullable', 'integer'],
            'items.*.due_at' => ['nullable', 'date'],
        ]);

        return ApiResponse::created($this->work->tasksFromMinutes($request->user(), $conversation, $meeting, $data['items']), __('api.created'));
    }

    // ------------------------------------------------------------------ polls (both kinds)

    /** POST groups/{c}/polls `{question, options[], multiple?, anonymous?}`. */
    public function poll(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
            'options' => ['required', 'array', 'min:2', 'max:12'],
            'options.*' => ['required', 'string', 'max:100'],
            'multiple' => ['nullable', 'boolean'],
            'anonymous' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::created($this->polls->create($request->user(), $conversation, $data['question'], $data['options'], (bool) ($data['multiple'] ?? false), (bool) ($data['anonymous'] ?? false)), __('api.created'));
    }
}
