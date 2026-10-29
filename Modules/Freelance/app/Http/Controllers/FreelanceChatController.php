<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Modules\Freelance\Services\Chat\FreelanceChatService;

/**
 * The client-freelancer chat (FL-006), one controller for both prefixes: the freelancer at
 * `freelance/v1/chat/*` and the client at `mobile/v1/freelance/chat/*`. Whoever calls only ever sees
 * the rooms they are in (anything else answers 404).
 */
class FreelanceChatController extends Controller
{
    public function __construct(private readonly FreelanceChatService $chat) {}

    public function conversations(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $page = $this->chat->list($me, min(max($request->integer('per_page', 20), 1), 50));
        $data = $page->getCollection()->map(fn ($c) => $this->chat->presentConversation($c, $me))->values();

        return ApiResponse::fromPaginator($page, $data, __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success($this->chat->presentConversation($this->chat->forParty($me, $uuid), $me), __('api.retrieved'));
    }

    public function messages(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $me = $this->me($request);
        $side = FreelanceChatService::side($me);
        $conversation = $this->chat->forParty($me, $uuid);

        $rows = $this->chat->messages($conversation, (int) ($data['limit'] ?? 30), $data['before'] ?? null);
        $items = $rows->map(fn ($m) => FreelanceChatService::present($m, $side))->values();

        return ApiResponse::success($items, __('api.retrieved'), 200, [
            'has_more' => $rows->count() === (int) ($data['limit'] ?? 30),
            'next_before' => $rows->isEmpty() ? null : $rows->last()->id,
        ]);
    }

    public function send(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.FreelanceChatService::BODY_MAX],
            'file' => ['nullable', 'file', 'max:'.(int) config('freelance.upload_max_kb'), 'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip', new \Modules\Freelance\Rules\CleanUpload],
            'client_id' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($request);
        $conversation = $this->chat->forParty($me, $uuid);

        return ApiResponse::success($this->chat->send($conversation, $me, $data['body'] ?? null, $request->file('file'), $data['client_id'] ?? null), __('api.created'), 201);
    }

    public function read(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $count = $this->chat->markRead($this->chat->forParty($me, $uuid), $me);

        return ApiResponse::success(['read' => $count], __('api.updated'));
    }

    public function destroy(Request $request, int $message): JsonResponse
    {
        $this->chat->delete($this->me($request), $message);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** The freelancer on `freelance_api`, the client on `user_api`. */
    private function me(Request $request): Model
    {
        return $request->user('freelance_api') ?? $request->user('user_api');
    }
}
