<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ChatAiImageService;
use Modules\Chat\Services\ChatAiService;
use Modules\Chat\Services\MessageService;

/**
 * AI in the chat — each call is one tap by the person (nothing is sent to the AI by itself).
 */
class ChatAiController extends Controller
{
    public function __construct(private readonly ChatAiService $ai) {}

    public function capabilities(Request $request)
    {
        return ApiResponse::success($this->ai->capabilities($request->user()), __('api.retrieved'));
    }

    /** GET conversations/{c}/ai/search — `?q=`: this chat by what the words mean (DORR AI spec 133–139). */
    public function searchByMeaning(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return ApiResponse::success($this->ai->searchByMeaning($request->user(), $conversation, $data['q']), __('api.retrieved'));
    }

    /** POST conversations/{c}/ai/capsule — `{messages?[]}`: a summary to carry this chat's work on elsewhere (DORR AI spec 121–132). */
    public function capsule(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'messages' => ['nullable', 'array', 'max:200'],
            'messages.*' => ['uuid'],
        ]);

        return ApiResponse::success($this->ai->capsule($request->user(), $conversation, $data['messages'] ?? null), __('api.retrieved'));
    }

    /** GET ai/images — every image made or changed with AI in my chats (spec 50, 51). */
    public function aiImages(Request $request, MessageService $messages)
    {
        return ApiResponse::success($messages->aiImages($request->user()), __('api.retrieved'));
    }

    /** GET ai/image/quota — today's AI images on my plan, before I start (read-only). */
    public function imageQuota(Request $request, ChatAiImageService $images)
    {
        return ApiResponse::success($images->quota($request->user()), __('api.retrieved'));
    }

    /** POST conversations/{c}/ai/image — `{prompt, style?}` (spec 50): a draft image only I see, to send or drop. */
    public function generateImage(Request $request, ChatConversation $conversation, ChatAiImageService $images)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
            'style' => ['nullable', Rule::in(array_keys(ChatAiImageService::STYLES))],
        ]);

        return ApiResponse::success($images->generate($request->user(), $conversation, $data['prompt'], $data['style'] ?? null), __('api.created'));
    }

    /** POST messages/{m}/ai/edit-image — `{prompt}` (spec 51): an image in the chat, changed as described, as a draft. */
    public function editImage(Request $request, ChatMessage $message, ChatAiImageService $images)
    {
        $data = $request->validate(['prompt' => ['required', 'string', 'max:1000']]);

        return ApiResponse::success($images->edit($request->user(), $message, $data['prompt']), __('api.created'));
    }

    /** POST conversations/{c}/ai/image/send — `{draft, body?, uuid?}`: the draft as an image message, marked as made by AI. */
    public function sendImage(Request $request, ChatConversation $conversation, ChatAiImageService $images, MessageService $messages)
    {
        $data = $request->validate([
            'draft' => ['required', 'uuid'],
            'body' => ['nullable', 'string', 'max:4000'],
            'uuid' => ['nullable', 'uuid'],
        ]);
        $me = $request->user();

        return ApiResponse::created($messages->presentOne($me, $images->send($me, $conversation, $data['draft'], $data)), __('api.created'));
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

    /** POST conversations/{c}/ask — `{question, messages?[]}` (spec 125). */
    public function ask(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'messages' => ['nullable', 'array', 'max:200'],
            'messages.*' => ['uuid'],
        ]);

        return ApiResponse::success($this->ai->askAbout($request->user(), $conversation, $data['question'], $data['messages'] ?? null), __('api.retrieved'));
    }

    /** POST conversations/{c}/commitments — `{messages?[], timezone?}` (spec 126): suggestions only. */
    public function commitments(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'messages' => ['nullable', 'array', 'max:200'],
            'messages.*' => ['uuid'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        return ApiResponse::success($this->ai->commitments($request->user(), $conversation, $data['messages'] ?? null, $data['timezone'] ?? 'UTC'), __('api.retrieved'));
    }

    public function smartReplies(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->ai->smartReplies($request->user(), $conversation), __('api.retrieved'));
    }
}
