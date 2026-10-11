<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatCall;
use Modules\Chat\Models\ChatCallLive;
use Modules\Chat\Services\CallLiveService;

/** A call broadcast live (spec 93): start / end it, watch it by its link, share its card. */
class CallLiveController extends Controller
{
    public function __construct(private readonly CallLiveService $lives) {}

    /** POST calls/{call}/live — `{title?, audience: members|link}` */
    public function start(Request $request, ChatCall $call)
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:120'], 'audience' => ['nullable', Rule::in(['members', 'link'])]]);
        $live = $this->lives->start($request->user(), $call, $data['title'] ?? null, $data['audience'] ?? 'members');

        return ApiResponse::created($this->lives->present($request->user(), $live), __('api.created'));
    }

    /** POST lives/{live}/end */
    public function end(Request $request, ChatCallLive $live)
    {
        return ApiResponse::success($this->lives->present($request->user(), $this->lives->end($request->user(), $live)), __('api.updated'));
    }

    /** GET lives/watch/{token} */
    public function show(Request $request, string $token)
    {
        return ApiResponse::success($this->lives->show($request->user(), $token), __('api.retrieved'));
    }

    /** POST lives/watch/{token} — `{live, join{url, room, token}|null}` (null once it ended) */
    public function watch(Request $request, string $token)
    {
        return ApiResponse::success($this->lives->watch($request->user(), $token), __('api.retrieved'));
    }

    public function ping(Request $request, string $token)
    {
        return ApiResponse::success($this->lives->ping($request->user(), $token), __('api.updated'));
    }

    /** POST lives/watch/{token}/share — `{conversation_ids[]}` */
    public function share(Request $request, string $token)
    {
        $ids = $request->validate(['conversation_ids' => ['required', 'array', 'min:1', 'max:20'], 'conversation_ids.*' => ['uuid']])['conversation_ids'];

        return ApiResponse::success(['sent' => $this->lives->share($request->user(), $token, $ids)], __('api.created'));
    }
}
