<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\ChatOverviewService;

/** "What I missed" (123), money in a chat (66), the privacy center (127). */
class ChatOverviewController extends Controller
{
    public function __construct(private readonly ChatOverviewService $overview) {}

    /** GET catch-up?since=ISO — default the last 24 hours, at most 7 days. */
    public function catchUp(Request $request)
    {
        $data = $request->validate(['since' => ['nullable', 'date']]);

        return ApiResponse::success($this->overview->catchUp($request->user(), $data['since'] ?? null), __('api.retrieved'));
    }

    /** GET conversations/{c}/money */
    public function money(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->overview->money($request->user(), $conversation), __('api.retrieved'));
    }

    /** GET privacy/center */
    public function privacyCenter(Request $request)
    {
        return ApiResponse::success($this->overview->privacyCenter($request->user()), __('api.retrieved'));
    }
}
