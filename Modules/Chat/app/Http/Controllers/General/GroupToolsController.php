<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\GroupNotifier;
use Modules\Chat\Services\GroupToolsService;
use Modules\Chat\Services\MessageService;

/**
 * Educational / work groups: announcements (GR-008), my notice settings (GR-009) and the activity
 * log (GR-010). Permissions are checked in GroupToolsService.
 */
class GroupToolsController extends Controller
{
    public function __construct(
        private readonly GroupToolsService $tools,
        private readonly MessageService $messages,
    ) {}

    /** GET groups/{c}/announcements — the latest 50, newest first (messages). */
    public function announcements(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->tools->announcements($request->user(), $conversation), __('api.retrieved'));
    }

    /** POST groups/{c}/announcements `{body}` — needs `announcements.post`; pinned 30 days. */
    public function announce(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:4000']]);
        $me = $request->user();
        $message = $this->tools->announce($me, $conversation, $data['body']);

        return ApiResponse::created($this->messages->presentOne($me, $message), __('api.created'));
    }

    /** GET groups/{c}/notification-prefs — `{types[], muted[]}`. */
    public function prefs(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->tools->notificationPrefs($request->user(), $conversation), __('api.retrieved'));
    }

    /** PUT groups/{c}/notification-prefs — `{prefs: {type: pushed?}}`. */
    public function setPrefs(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'prefs' => ['required', 'array'],
            'prefs.*' => ['boolean'],
        ]);
        $unknown = array_diff(array_keys($data['prefs']), GroupNotifier::TYPES);
        abort_if($unknown !== [], 422, 'Unknown notice type.');

        return ApiResponse::success($this->tools->notificationPrefs($request->user(), $conversation, $data['prefs']), __('api.updated'));
    }

    /** GET groups/{c}/audit-log?page= — needs `audit.view`; 30 a page, newest first. */
    public function auditLog(Request $request, ChatConversation $conversation)
    {
        $page = (int) ($request->validate(['page' => ['nullable', 'integer', 'min:1']])['page'] ?? 1);

        return ApiResponse::success($this->tools->auditLog($request->user(), $conversation, max(1, $page)), __('api.retrieved'));
    }
}
