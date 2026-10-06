<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\ChannelService;
use Modules\Chat\Services\ConversationService;

/**
 * Channels: create, discover, look one up (uuid or @handle), follow / unfollow, set the @handle.
 * Everything else (posting, editing info, admins, invite link) goes through the group endpoints.
 */
class ChannelController extends Controller
{
    public function __construct(
        private readonly ChannelService $channels,
        private readonly ConversationService $conversations,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'handle' => ['nullable', 'string', 'max:40'],
            'is_public' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'max:10240'],
            'category_id' => ['nullable', 'integer'],
        ]);
        $me = $request->user();

        $row = $this->channels->create($me, $data['name'], $data['description'] ?? null, $data['handle'] ?? null, $request->boolean('is_public', true), $request->file('avatar'), isset($data['category_id']) ? (int) $data['category_id'] : null);

        return ApiResponse::created($this->conversations->resource($me, $row), __('api.created'));
    }

    public function discover(Request $request)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:60'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'], 'category_id' => ['nullable', 'integer']]);
        $me = $request->user();
        $page = $this->channels->discover($me, $data['search'] ?? null, (int) ($data['per_page'] ?? 20), isset($data['category_id']) ? (int) $data['category_id'] : null);

        return ApiResponse::success($page->getCollection()->map(fn (ChatConversation $c) => $this->channels->present($me, $c))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    public function show(Request $request, string $channel)
    {
        $me = $request->user();

        return ApiResponse::success($this->channels->present($me, $this->channels->find($me, $channel)), __('api.retrieved'));
    }

    public function follow(Request $request, string $channel)
    {
        $me = $request->user();

        return ApiResponse::success($this->conversations->resource($me, $this->channels->follow($me, $this->channels->find($me, $channel))), __('chat.channel_followed'));
    }

    public function unfollow(Request $request, ChatConversation $conversation)
    {
        $this->channels->unfollow($request->user(), $conversation);

        return ApiResponse::success(null, __('api.updated'));
    }

    /** GET chat/channels/directory?search= — grouped by category, the most followed first. */
    public function directory(Request $request)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:60']]);

        return ApiResponse::success($this->channels->directory($request->user(), $data['search'] ?? null), __('api.retrieved'));
    }

    public function category(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['category_id' => ['present', 'nullable', 'integer']]);
        $me = $request->user();
        $this->channels->setCategory($me, $conversation, $data['category_id'] !== null ? (int) $data['category_id'] : null);

        return ApiResponse::success($this->conversations->show($me, $conversation->refresh()), __('api.updated'));
    }

    /**
     * GET chat/channels/{conversation}/verification — the ✔'s state and the packages to buy it
     * (paid through wallet/checkouts, purpose `chat_channel_verification`).
     */
    public function verification(Request $request, ChatConversation $conversation)
    {
        $country = currentCountry();
        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        return ApiResponse::success($this->channels->verification($request->user(), $conversation, $country), __('api.retrieved'));
    }

    public function handle(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['handle' => ['present', 'nullable', 'string', 'max:40']]);
        $me = $request->user();
        $this->channels->setHandle($me, $conversation, $data['handle']);

        return ApiResponse::success($this->conversations->show($me, $conversation->refresh()), __('api.updated'));
    }
}
