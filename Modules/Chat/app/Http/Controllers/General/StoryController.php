<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\ChatStory;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Services\StoryService;
use Modules\Chat\Support\ParticipantType;

/**
 * Stories (docs/modules/chat/API.md#stories).
 */
class StoryController extends Controller
{
    public function __construct(private readonly StoryService $stories) {}

    public function index(Request $request)
    {
        return ApiResponse::success($this->stories->feed($request->user()), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $maxKb = ChatSetting::current()->max_file_size_mb * 1024;
        $data = $request->validate([
            'type' => ['required', Rule::in(['text', 'image', 'video'])],
            'body' => ['nullable', 'string', 'max:700'],
            'style' => ['nullable', 'array'],
            'style.background' => ['nullable', 'string', 'max:40'],
            'style.font' => ['nullable', 'string', 'max:20'],
            'style.align' => ['nullable', Rule::in(['start', 'center', 'end'])],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'allow_replies' => ['nullable', 'boolean'],
            'file' => [
                'nullable', 'file', 'max:'.$maxKb,
                $request->input('type') === 'video' ? 'mimes:mp4,mov,3gp,mkv,webm' : 'mimes:jpeg,jpg,png,webp,gif,heic,heif',
            ],
        ]);

        $story = $this->stories->create($request->user(), $data, $request->file('file'));

        return ApiResponse::created(['id' => $story->uuid, 'expires_at' => $story->expires_at?->toIso8601String()], __('api.created'));
    }

    public function destroy(Request $request, ChatStory $story)
    {
        $this->stories->delete($request->user(), $story);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function view(Request $request, ChatStory $story)
    {
        $this->stories->view($request->user(), $story);

        return ApiResponse::success(null, 'OK');
    }

    public function react(Request $request, ChatStory $story)
    {
        $data = $request->validate(['emoji' => ['present', 'nullable', 'string', 'max:32']]);
        $this->stories->react($request->user(), $story, $data['emoji']);

        return ApiResponse::success(null, __('api.updated'));
    }

    public function reply(Request $request, ChatStory $story)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $me = $request->user();
        $message = $this->stories->reply($me, $story, $data['body']);

        return ApiResponse::created(app(MessageService::class)->presentOne($me, $message), __('api.created'));
    }

    public function viewers(Request $request, ChatStory $story)
    {
        return ApiResponse::success($this->stories->viewers($request->user(), $story), __('api.retrieved'));
    }

    public function privacy(Request $request)
    {
        return ApiResponse::success($this->stories->privacyOf($request->user()), __('api.retrieved'));
    }

    public function updatePrivacy(Request $request)
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(['contacts', 'except', 'only'])],
            'except' => ['sometimes', 'array', 'max:5000'], 'except.*' => ['integer'],
            'only' => ['sometimes', 'array', 'max:5000'], 'only.*' => ['integer'],
        ]);

        return ApiResponse::success(
            $this->stories->updatePrivacy($request->user(), $data['audience'], $data['except'] ?? null, $data['only'] ?? null),
            __('api.updated'),
        );
    }

    public function mute(Request $request)
    {
        $data = $request->validate(['participant_id' => ['required', 'integer'], 'muted' => ['required', 'boolean']]);
        $other = ParticipantType::modelClassFor('user')::query()->find($data['participant_id']) ?? throw ChatException::userNotFound();
        $this->stories->mute($request->user(), $other, (bool) $data['muted']);

        return ApiResponse::success($this->stories->feed($request->user()), __('api.updated'));
    }
}
