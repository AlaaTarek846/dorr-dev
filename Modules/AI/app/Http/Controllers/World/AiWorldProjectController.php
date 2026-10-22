<?php

namespace Modules\AI\Http\Controllers\World;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Http\Resources\World\AiWorldProjectResource;
use Modules\AI\Models\World\AiWorldProject;
use Modules\AI\Models\World\AiWorldScene;
use Modules\AI\Services\World\AiWorldAccess;
use Modules\AI\Services\World\AiWorldDirectorService;
use Modules\AI\Services\World\AiWorldProjectService;

/** Trip-video projects for the signed-in user or provider. Every lookup is scoped to the caller. */
class AiWorldProjectController extends Controller
{
    public function __construct(protected AiWorldProjectService $projects, protected AiWorldAccess $access, protected AiWorldDirectorService $director) {}

    public function index(Request $request)
    {
        $items = AiWorldProject::query()->where('owner_type', $request->user()->getMorphClass())->where('owner_id', $request->user()->getAuthIdentifier())->with('scenes')->latest('id')->limit(50)->get();

        return ApiResponse::success(AiWorldProjectResource::collection($items)->resolve());
    }

    /** The Director: proposes an editable scene list with a price. Charges nothing and creates nothing. */
    public function plan(Request $request)
    {
        $this->access->assertEnabled();
        $data = $request->validate([
            'brief' => ['required', 'string', 'min:3', 'max:2000'],
            'quality' => ['nullable', 'string', 'in:standard,premium'],
            'location_ids' => ['nullable', 'array', 'max:10'],
            'location_ids.*' => ['integer'],
        ]);

        $countryId = $this->access->countryId() ?? throw new AiWorldException('price_missing', 422);
        $language = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return ApiResponse::success($this->director->plan($data['brief'], array_map('intval', $data['location_ids'] ?? []), $countryId, $data['quality'] ?? 'standard', $language));
    }

    public function store(Request $request)
    {
        $this->access->assertEnabled();
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:191'],
            'brief' => ['nullable', 'string', 'max:2000'],
            'quality' => ['nullable', 'string', 'in:standard,premium'],
            'identity_profile_id' => ['nullable', 'integer'],
            'scenes' => ['required', 'array', 'min:1', 'max:20'],
            'scenes.*.prompt' => ['required', 'string', 'max:1500'],
            'scenes.*.seconds' => ['required', 'integer'],
            'scenes.*.location_id' => ['nullable', 'integer', 'exists:ai_world_locations,id'],
        ]);

        $countryId = $this->access->countryId();

        if ($countryId === null) {
            throw new AiWorldException('price_missing', 422);
        }

        $project = $this->projects->create($request->user(), $countryId, $data);

        return ApiResponse::success((new AiWorldProjectResource($project))->resolve(), 'OK', 201);
    }

    public function show(Request $request, int $id)
    {
        return ApiResponse::success((new AiWorldProjectResource($this->mine($request, $id)->load('scenes')))->resolve());
    }

    public function confirm(Request $request, int $id)
    {
        return ApiResponse::success((new AiWorldProjectResource($this->projects->confirm($this->mine($request, $id))))->resolve());
    }

    public function cancel(Request $request, int $id)
    {
        return ApiResponse::success((new AiWorldProjectResource($this->projects->cancel($this->mine($request, $id))->load('scenes')))->resolve());
    }

    public function approveScene(Request $request, int $id, int $sceneId)
    {
        return ApiResponse::success((new AiWorldProjectResource($this->projects->approveScene($this->scene($request, $id, $sceneId))))->resolve());
    }

    public function regenerateScene(Request $request, int $id, int $sceneId)
    {
        $this->projects->regenerateScene($this->scene($request, $id, $sceneId));

        return ApiResponse::success((new AiWorldProjectResource($this->mine($request, $id)->load('scenes')))->resolve());
    }

    protected function mine(Request $request, int $id): AiWorldProject
    {
        $project = AiWorldProject::query()->where('owner_type', $request->user()->getMorphClass())->where('owner_id', $request->user()->getAuthIdentifier())->find($id);

        return $project ?? throw new AiWorldException('not_found', 404);
    }

    protected function scene(Request $request, int $id, int $sceneId): AiWorldScene
    {
        return AiWorldScene::query()->where('project_id', $this->mine($request, $id)->id)->find($sceneId) ?? throw new AiWorldException('not_found', 404);
    }
}
