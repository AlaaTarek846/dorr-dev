<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiUserProjectRequest;
use Modules\AI\Http\Resources\AiUserProjectResource;
use Modules\AI\Services\AiUserProjectService;

/**
 * The person's own DORR AI projects (spec 151, 315): conversations and files grouped around one
 * piece of work, with instructions every answer in it reads.
 */
class AiUserProjectController extends Controller
{
    public function __construct(protected AiUserProjectService $projects) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(AiUserProjectResource::collection($this->projects->list($this->owner($request))), __('api.retrieved'));
    }

    public function show(Request $request, int $project): JsonResponse
    {
        return ApiResponse::success(new AiUserProjectResource($this->projects->show($this->owner($request), $project)), __('api.retrieved'));
    }

    public function store(AiUserProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($this->owner($request), $request->validated());

        return ApiResponse::created(new AiUserProjectResource($this->projects->show($this->owner($request), $project->id)), __('api.created'));
    }

    public function update(AiUserProjectRequest $request, int $project): JsonResponse
    {
        $this->projects->update($this->owner($request), $project, $request->validated());

        return ApiResponse::success(new AiUserProjectResource($this->projects->show($this->owner($request), $project)), __('api.updated'));
    }

    public function destroy(Request $request, int $project): JsonResponse
    {
        $this->projects->delete($this->owner($request), $project);

        return ApiResponse::noContent(__('api.deleted'));
    }

    public function addConversation(Request $request, int $project, int $conversation): JsonResponse
    {
        $this->projects->addConversation($this->owner($request), $project, $conversation);

        return ApiResponse::success(new AiUserProjectResource($this->projects->show($this->owner($request), $project)), __('api.updated'));
    }

    public function removeConversation(Request $request, int $project, int $conversation): JsonResponse
    {
        $this->projects->removeConversation($this->owner($request), $project, $conversation);

        return ApiResponse::noContent(__('api.deleted'));
    }

    public function addFile(Request $request, int $project, int $file): JsonResponse
    {
        $this->projects->addFile($this->owner($request), $project, $file);

        return ApiResponse::success(new AiUserProjectResource($this->projects->show($this->owner($request), $project)), __('api.updated'));
    }

    public function removeFile(Request $request, int $project, int $file): JsonResponse
    {
        $this->projects->removeFile($this->owner($request), $project, $file);

        return ApiResponse::noContent(__('api.deleted'));
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
