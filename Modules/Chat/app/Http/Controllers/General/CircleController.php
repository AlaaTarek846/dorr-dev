<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Http\Resources\ConversationResource;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatPrivacyCircle;
use Modules\Chat\Services\CircleService;

/**
 * Privacy circles: mine, create / change / delete, and putting a chat in one. Their chats are
 * listed with `GET conversations?circle={id}`.
 */
class CircleController extends Controller
{
    public function __construct(private readonly CircleService $circles) {}

    public function index(Request $request)
    {
        return ApiResponse::success($this->circles->list($request->user()), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $circle = $this->circles->create($request->user(), $this->validated($request, true));

        return ApiResponse::created($this->circles->present($circle), __('api.created'));
    }

    public function update(Request $request, ChatPrivacyCircle $circle)
    {
        return ApiResponse::success($this->circles->present($this->circles->update($request->user(), $circle, $this->validated($request, false))), __('api.updated'));
    }

    public function destroy(Request $request, ChatPrivacyCircle $circle)
    {
        $this->circles->delete($request->user(), $circle);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** PUT conversations/{c}/circle — `{circle_id}` (null takes it out). */
    public function assign(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['circle_id' => ['present', 'nullable', 'string', 'max:64']]);
        $me = $request->user();
        $row = $this->circles->assign($me, $conversation, $data['circle_id']);

        return ApiResponse::success(new ConversationResource($row, $me), __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:60'],
            'masked_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'emoji' => ['sometimes', 'nullable', 'string', 'max:16'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            'disclosure' => ['sometimes', Rule::in(\Modules\Chat\Models\ChatPrivacyCircle::LEVELS)],
            'hide_from_list' => ['sometimes', 'boolean'],
            'locked' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}
