<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\CallType;
use Modules\Chat\Models\ChatCallLink;
use Modules\Chat\Services\CallLinkService;

/**
 * Call links (WhatsApp list 72): make one, see mine, open one, join it, cancel it.
 * Joining returns `join` — the same LiveKit `{url, room, token}` a normal call gets.
 */
class CallLinkController extends Controller
{
    public function __construct(private readonly CallLinkService $links) {}

    public function index(Request $request)
    {
        $me = $request->user();

        return ApiResponse::success($this->links->mine($me)->map(fn (ChatCallLink $l) => $this->links->payload($me, $l))->values(), __('api.retrieved'));
    }

    /** `{type: audio|video}` */
    public function store(Request $request)
    {
        $data = $request->validate(['type' => ['required', Rule::enum(CallType::class)]]);
        $me = $request->user();

        return ApiResponse::created($this->links->payload($me, $this->links->create($me, CallType::from($data['type']))), __('api.created'));
    }

    public function show(Request $request, string $token)
    {
        return ApiResponse::success($this->links->payload($request->user(), $this->links->find($token)), __('api.retrieved'));
    }

    public function join(Request $request, string $token)
    {
        $me = $request->user();
        $link = $this->links->find($token);

        return ApiResponse::success(['link' => $this->links->payload($me, $link), 'join' => $this->links->join($me, $link)], __('api.updated'));
    }

    public function destroy(Request $request, string $token)
    {
        $this->links->revoke($request->user(), $token);

        return ApiResponse::success(null, __('api.deleted'));
    }
}
