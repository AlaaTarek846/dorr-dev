<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Services\ScheduledMessageService;

/**
 * Scheduled messages: mine in a chat, schedule one, change it, cancel it, or send it now.
 * `send_at` is any ISO-8601 time (with its offset); stored and returned in UTC.
 */
class ScheduledMessageController extends Controller
{
    public function __construct(
        private readonly ScheduledMessageService $scheduled,
        private readonly MessageService $messages,
    ) {}

    public function index(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->scheduled->list($request->user(), $conversation), __('api.retrieved'));
    }

    public function store(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'send_at' => ['required', 'date'],
            'silent' => ['sometimes', 'boolean'],
        ]);

        $row = $this->scheduled->schedule($request->user(), $conversation, $data['body'], Carbon::parse($data['send_at']), (bool) ($data['silent'] ?? false));

        return ApiResponse::created($row->load('conversation')->present(), __('api.created'));
    }

    public function update(Request $request, string $scheduled)
    {
        $data = $request->validate([
            'body' => ['sometimes', 'string', 'max:10000'],
            'send_at' => ['sometimes', 'date'],
            'silent' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['send_at'])) {
            $data['send_at'] = Carbon::parse($data['send_at']);
        }

        return ApiResponse::success($this->scheduled->update($request->user(), $scheduled, $data)->present(), __('api.updated'));
    }

    public function destroy(Request $request, string $scheduled)
    {
        $this->scheduled->cancel($request->user(), $scheduled);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function sendNow(Request $request, string $scheduled)
    {
        $message = $this->scheduled->sendNow($request->user(), $scheduled);

        return ApiResponse::success($this->messages->presentOne($request->user(), $message), __('api.created'));
    }
}
