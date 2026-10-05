<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ChatAiService;

/**
 * AI in the chat — each call is one tap by the person (nothing is sent to the AI by itself).
 */
class ChatAiController extends Controller
{
    public function __construct(private readonly ChatAiService $ai) {}

    public function capabilities()
    {
        return ApiResponse::success($this->ai->capabilities(), __('api.retrieved'));
    }

    public function translate(Request $request, ChatMessage $message)
    {
        $data = $request->validate(['to' => ['nullable', Rule::in(array_keys(ChatAiService::LANGUAGES))]]);
        // Into my own language unless I pick another.
        $to = $data['to'] ?? (array_key_exists(app()->getLocale(), ChatAiService::LANGUAGES) ? app()->getLocale() : 'en');

        return ApiResponse::success($this->ai->translate($request->user(), $message, $to), __('api.retrieved'));
    }

    public function transcribe(Request $request, ChatMessage $message)
    {
        return ApiResponse::success($this->ai->transcribe($request->user(), $message), __('api.retrieved'));
    }

    public function summarize(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['unread_only' => ['sometimes', 'boolean']]);

        return ApiResponse::success($this->ai->summarize($request->user(), $conversation, (bool) ($data['unread_only'] ?? false)), __('api.retrieved'));
    }

    public function smartReplies(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->ai->smartReplies($request->user(), $conversation), __('api.retrieved'));
    }
}
