<?php

namespace Modules\Discover\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\ConversationService;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Models\DiscoverFollow;
use Modules\Discover\Services\DiscoverAiService;
use Modules\Discover\Services\DiscoverChatService;
use Modules\Discover\Services\DiscoverService;

/**
 * DORR Discover in the app (spec 169–179). Every request may send `timezone` (where I am now):
 * each event keeps its own local time and gets mine next to it.
 */
class DiscoverController extends Controller
{
    public function __construct(private readonly DiscoverService $discover) {}

    public function home(Request $request)
    {
        $this->on();

        return ApiResponse::success($this->discover->home($request->user(), currentCountry(), $this->zone($request)), __('api.retrieved'));
    }

    /** GET events — browse and search (169–171, 173). */
    public function index(Request $request)
    {
        $this->on();
        $f = $request->validate($this->filterRules() + [
            'city_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer'],
        ]);
        if (empty($f['city_id']) && empty($f['country_id'])) {
            $f['country_id'] = currentCountry()?->id;
        }
        $result = $this->discover->search($request->user(), $this->near($f), $this->zone($request));

        return ApiResponse::success($result['items'], __('api.retrieved'), 200, $result['meta']);
    }

    /** GET travel?city_id=&from=&to= — what's on where I'm going, on my dates (AT-DISC-03). */
    public function travel(Request $request)
    {
        $f = $request->validate($this->filterRules() + [
            'city_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);
        $result = $this->discover->travel($request->user(), (int) $f['city_id'], $f['from'], $f['to'], $f, $this->zone($request));

        return ApiResponse::success($result, __('api.retrieved'));
    }

    /** POST ask {text} — DORR AI turns my words into filters, the events are real ones (181). */
    public function ask(Request $request, DiscoverAiService $ai)
    {
        $this->on();
        $data = $request->validate(['text' => ['required', 'string', 'min:2', 'max:500']]);

        return ApiResponse::success($ai->ask($request->user(), $data['text'], currentCountry(), $this->zone($request)), __('api.retrieved'));
    }

    public function show(Request $request, DiscoverEvent $event)
    {
        return ApiResponse::success($this->discover->show($request->user(), $event, $this->zone($request)), __('api.retrieved'));
    }

    public function categories()
    {
        return ApiResponse::success($this->discover->categories(), __('api.retrieved'));
    }

    /** GET cities?country_id= — all active cities (for travel), or one country's. */
    public function cities(Request $request)
    {
        $data = $request->validate(['country_id' => ['nullable', 'integer']]);

        return ApiResponse::success($this->discover->cities(isset($data['country_id']) ? (int) $data['country_id'] : null), __('api.retrieved'));
    }

    /** POST events/{event}/interest {notify} — saved, in my calendar, told when it changes (177). */
    public function interest(Request $request, DiscoverEvent $event)
    {
        $data = $request->validate(['notify' => ['sometimes', 'boolean']]);
        $interest = $this->discover->interest($request->user(), $event, (bool) ($data['notify'] ?? true));

        return ApiResponse::success($this->discover->present($event->refresh()->load(['category.translations', 'city.translations', 'country', 'organizer', 'media']), $this->zone($request), $interest), __('api.updated'));
    }

    public function uninterest(Request $request, DiscoverEvent $event)
    {
        $this->discover->uninterest($request->user(), $event);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** GET interests?past=1 */
    public function interests(Request $request)
    {
        return ApiResponse::success($this->discover->interests($request->user(), $this->zone($request), $request->boolean('past')), __('api.retrieved'));
    }

    public function follows(Request $request)
    {
        return ApiResponse::success($this->discover->follows($request->user()), __('api.retrieved'));
    }

    public function follow(Request $request)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(DiscoverFollow::KINDS)], 'target_id' => ['required', 'integer']]);

        return ApiResponse::success($this->discover->follow($request->user(), $data['kind'], (int) $data['target_id']), __('api.updated'));
    }

    public function unfollow(Request $request, int $follow)
    {
        return ApiResponse::success($this->discover->unfollow($request->user(), $follow), __('api.deleted'));
    }

    public function preferences(Request $request)
    {
        return ApiResponse::success($this->discover->presentPreferences($this->discover->preferences($request->user())), __('api.retrieved'));
    }

    public function savePreferences(Request $request)
    {
        $data = $request->validate([
            'categories' => ['sometimes', 'nullable', 'array', 'max:50'],
            'categories.*' => ['integer', 'exists:discover_categories,id'],
            'alerts' => ['sometimes', 'boolean'],
            'alert_days' => ['sometimes', 'integer', Rule::in([3, 7, 14, 30])],
            'family_only' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success($this->discover->savePreferences($request->user(), $data), __('api.updated'));
    }

    /** POST events/{event}/share {conversation_id, comment?, poll?} (178). */
    public function share(Request $request, DiscoverEvent $event, DiscoverChatService $chat)
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'string'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'poll' => ['sometimes', 'boolean'],
        ]);
        $conversation = ChatConversation::query()->where('uuid', $data['conversation_id'])->firstOrFail();
        $result = $chat->share($request->user(), $event, $conversation, $data['comment'] ?? null, (bool) ($data['poll'] ?? false));

        return ApiResponse::created([
            'message_id' => $result['message']->uuid,
            'poll_id' => $result['poll']?->uuid,
            'conversation_id' => $conversation->uuid,
        ], __('api.created'));
    }

    /** POST events/{event}/room {members[]} — a group to go together (179). */
    public function room(Request $request, DiscoverEvent $event, DiscoverChatService $chat, ConversationService $conversations)
    {
        $data = $request->validate(['members' => ['required', 'array', 'min:1', 'max:200'], 'members.*' => ['integer', 'distinct']]);
        $me = $request->user();
        $result = $chat->room($me, $event, array_map('intval', $data['members']));

        return ApiResponse::created([
            'conversation' => $conversations->resource($me, $result['participant']),
            'not_added' => $result['not_added'],
        ], __('api.created'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function filterRules(): array
    {
        return [
            'category_ids' => ['nullable', 'array', 'max:50'],
            'category_ids.*' => ['integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'free' => ['nullable', 'boolean'],
            'family' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'km' => ['nullable', 'numeric', 'min:1', 'max:200'],
            'sort' => ['nullable', Rule::in(['soon', 'popular'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private function near(array $f): array
    {
        if (isset($f['lat'], $f['lng'])) {
            $f['near'] = ['lat' => $f['lat'], 'lng' => $f['lng'], 'km' => $f['km'] ?? 25];
        }

        return $f;
    }

    private function zone(Request $request): string
    {
        return $this->discover->zone($request->input('timezone'), $request->user());
    }

    private function on(): void
    {
        $this->discover->assertOn(currentCountry());
    }
}
