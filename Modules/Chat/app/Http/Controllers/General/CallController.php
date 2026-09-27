<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\CallType;
use Modules\Chat\Models\ChatCall;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\CallService;
use Modules\Chat\Services\ConversationService;

/**
 * Voice / video calls (LiveKit). Starting or answering returns `join`: the LiveKit server URL,
 * the room, and this person's token — everything the app's LiveKit SDK needs to connect.
 */
class CallController extends Controller
{
    public function __construct(private readonly CallService $calls) {}

    public function index(Request $request)
    {
        $me = $request->user();
        $page = $this->calls->history($me);

        return ApiResponse::success($page->getCollection()->map(fn (ChatCall $c) => $this->calls->payload($me, $c))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    public function store(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['type' => ['required', Rule::enum(CallType::class)]]);
        $me = $request->user();
        $result = $this->calls->start($me, $conversation, CallType::from($data['type']));

        return ApiResponse::created(['call' => $this->calls->payload($me, $result['call']), 'join' => $result['join']], __('api.created'));
    }

    public function show(Request $request, ChatCall $call)
    {
        $me = $request->user();
        app(ConversationService::class)->participantOf($me, $call->conversation);

        return ApiResponse::success($this->calls->payload($me, $call), __('api.retrieved'));
    }

    public function accept(Request $request, ChatCall $call)
    {
        $me = $request->user();
        $result = $this->calls->accept($me, $call);

        return ApiResponse::success(['call' => $this->calls->payload($me, $result['call']), 'join' => $result['join']], __('api.updated'));
    }

    public function decline(Request $request, ChatCall $call)
    {
        $me = $request->user();

        return ApiResponse::success(['call' => $this->calls->payload($me, $this->calls->decline($me, $call))], __('api.updated'));
    }

    public function leave(Request $request, ChatCall $call)
    {
        $me = $request->user();

        return ApiResponse::success(['call' => $this->calls->payload($me, $this->calls->leave($me, $call))], __('api.updated'));
    }

    public function token(Request $request, ChatCall $call)
    {
        return ApiResponse::success(['join' => $this->calls->token($request->user(), $call)], __('api.retrieved'));
    }
}
