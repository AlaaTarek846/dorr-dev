<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Http\Requests\SendMessageRequest;
use Modules\Chat\Models\ChatBroadcastList;
use Modules\Chat\Services\BroadcastService;

/**
 * Broadcast lists: mine, create / rename / change people / delete, and send to one.
 */
class BroadcastController extends Controller
{
    public function __construct(private readonly BroadcastService $broadcasts) {}

    public function index(Request $request)
    {
        $me = $request->user();
        $rows = ChatBroadcastList::query()->ownedBy($me)->latest('updated_at')->get()->map(fn ($l) => $this->broadcasts->present($me, $l));

        return ApiResponse::success($rows->values(), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer'],
        ]);
        $me = $request->user();
        $list = $this->broadcasts->create($me, $data['name'] ?? null, $data['members']);

        return ApiResponse::created($this->broadcasts->present($me, $list, true), __('api.created'));
    }

    public function show(Request $request, ChatBroadcastList $broadcast)
    {
        $me = $request->user();
        abort_unless($broadcast->isOwnedBy($me), 404);

        return ApiResponse::success($this->broadcasts->present($me, $broadcast, true), __('api.retrieved'));
    }

    public function update(Request $request, ChatBroadcastList $broadcast)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'members' => ['sometimes', 'array', 'min:1'],
            'members.*' => ['integer'],
        ]);
        $me = $request->user();
        $list = $this->broadcasts->update($me, $broadcast, $data['name'] ?? null, $data['members'] ?? null, array_key_exists('name', $data));

        return ApiResponse::success($this->broadcasts->present($me, $list, true), __('api.updated'));
    }

    public function destroy(Request $request, ChatBroadcastList $broadcast)
    {
        $this->broadcasts->delete($request->user(), $broadcast);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST broadcasts/{id}/messages — the same fields (and files) as a normal message. */
    public function send(SendMessageRequest $request, ChatBroadcastList $broadcast)
    {
        $result = $this->broadcasts->send($request->user(), $broadcast, $request->validated(), $request->file('files', []));

        return ApiResponse::created($result, __('chat.broadcast_sent', ['count' => $result['sent']]));
    }
}
