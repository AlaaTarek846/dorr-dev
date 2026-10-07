<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatDorrStory;

/**
 * Dorr's own stories (ads, news) — the fixed third circle on the home page. A photo, a video or a
 * text on a colour, an optional "Open" link, and when it shows.
 */
class ChatDorrStoryController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-dorr-stories', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index()
    {
        $rows = ChatDorrStory::query()->with('media')->orderBy('sort_order')->latest('id')->get()->map(fn ($s) => $this->present($s));

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(ChatDorrStory $chatDorrStory)
    {
        return ApiResponse::success($this->present($chatDorrStory->load('media')), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $story = $this->save(new ChatDorrStory(['uuid' => (string) Str::uuid()]), $this->validated($request, true), $request);

        return ApiResponse::created($this->present($story), __('api.created'));
    }

    /** POST (multipart: the photo / video can change). */
    public function update(Request $request, ChatDorrStory $chatDorrStory)
    {
        return ApiResponse::success($this->present($this->save($chatDorrStory, $this->validated($request, false), $request)), __('api.updated'));
    }

    public function status(Request $request, ChatDorrStory $chatDorrStory)
    {
        $chatDorrStory->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($chatDorrStory->load('media')), __('api.updated'));
    }

    public function destroy(ChatDorrStory $chatDorrStory)
    {
        $chatDorrStory->clearMediaCollection(ChatDorrStory::MEDIA);
        $chatDorrStory->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $type = $request->input('type');

        return $request->validate([
            'type' => ['required', Rule::in(['text', 'image', 'video'])],
            'body' => [$type === 'text' ? 'required' : 'nullable', 'string', 'max:700'],
            'style' => ['nullable', 'array'],
            'style.background' => ['nullable', 'string', 'max:40'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'link_url' => ['nullable', 'url:http,https', 'max:500'],
            'link_label' => ['nullable', 'string', 'max:60'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'file' => [
                $creating && $type !== 'text' ? 'required' : 'nullable', 'file', 'max:102400',
                $type === 'video' ? 'mimes:mp4,mov,3gp,mkv,webm' : 'mimes:jpeg,jpg,png,webp,gif',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(ChatDorrStory $story, array $data, Request $request): ChatDorrStory
    {
        DB::transaction(function () use ($story, $data, $request) {
            $story->fill(collect($data)->except('file')->all())->save();

            if ($request->hasFile('file')) {
                $story->clearMediaCollection(ChatDorrStory::MEDIA);
                $file = $request->file('file');
                $story->addMedia($file)
                    ->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'))
                    ->toMediaCollection(ChatDorrStory::MEDIA);
            }
        });

        return $story->refresh()->load('media');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatDorrStory $story): array
    {
        $media = $story->getFirstMedia(ChatDorrStory::MEDIA);

        return [
            'id' => $story->uuid,
            'type' => $story->type,
            'body' => $story->body,
            'style' => $story->style,
            'media' => $media === null ? null : ['url' => $media->getUrl(), 'mime_type' => $media->mime_type],
            'duration_ms' => $story->duration_ms,
            'link_url' => $story->link_url,
            'link_label' => $story->link_label,
            'starts_at' => $story->starts_at?->toIso8601String(),
            'ends_at' => $story->ends_at?->toIso8601String(),
            'status' => $story->status,
            'is_showing' => $story->isShowing(),
            'sort_order' => $story->sort_order,
            'views_count' => $story->views_count,
        ];
    }
}
