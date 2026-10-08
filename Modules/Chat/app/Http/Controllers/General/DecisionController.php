<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatDecisionArgument;
use Modules\Chat\Models\ChatGroupDecision;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\DecisionService;

/**
 * Group decisions (spec 119–120): make one from a message, approve / reject it (admins), the log.
 */
class DecisionController extends Controller
{
    public function __construct(private readonly DecisionService $decisions) {}

    /** POST messages/{m}/decision — `{title?, options?[]}` */
    public function store(Request $request, ChatMessage $message)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:300'],
            'options' => ['nullable', 'array', 'min:2', 'max:12'],
            'options.*' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'deadline_at' => ['nullable', 'date', 'after:now'],
        ]);
        $me = $request->user();
        $decision = $this->decisions->fromMessage($me, $message, $data['title'] ?? null, $data['options'] ?? null, $data['description'] ?? null, $data['deadline_at'] ?? null);

        return ApiResponse::created($this->decisions->present($me, $decision->load(['poll', 'source', 'createdBy', 'decidedBy'])), __('api.created'));
    }

    /** POST decisions/{id}/decide — `{approve, outcome?}` */
    public function decide(Request $request, ChatGroupDecision $decision)
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'outcome' => ['nullable', 'string', 'max:300']]);
        $me = $request->user();
        $decision = $this->decisions->decide($me, $decision, (bool) $data['approve'], $data['outcome'] ?? null);

        return ApiResponse::success($this->decisions->present($me, $decision->load(['poll', 'source', 'createdBy', 'decidedBy'])), __('api.updated'));
    }

    /** GET decisions/{id} — the decision room (spec 153). */
    public function show(Request $request, ChatGroupDecision $decision)
    {
        return ApiResponse::success($this->decisions->room($request->user(), $decision), __('api.retrieved'));
    }

    /** POST decisions/{id}/arguments — `{stance: pro|con|note, text, option_id?}` */
    public function argue(Request $request, ChatGroupDecision $decision)
    {
        $data = $request->validate(['stance' => ['required', Rule::in(ChatDecisionArgument::STANCES)], 'text' => ['required', 'string', 'max:500'], 'option_id' => ['nullable', 'string', 'max:20']]);
        $me = $request->user();
        $this->decisions->argue($me, $decision, $data['stance'], $data['text'], $data['option_id'] ?? null);

        return ApiResponse::created($this->decisions->room($me, $decision->refresh()), __('api.created'));
    }

    public function removeArgument(Request $request, ChatGroupDecision $decision, int $argument)
    {
        $me = $request->user();
        $this->decisions->removeArgument($me, $decision, $argument);

        return ApiResponse::success($this->decisions->room($me, $decision->refresh()), __('api.updated'));
    }

    /** GET groups/{c}/decisions?status= */
    public function index(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'approved', 'rejected'])]]);

        return ApiResponse::success($this->decisions->log($request->user(), $conversation, $data['status'] ?? null), __('api.retrieved'));
    }
}
