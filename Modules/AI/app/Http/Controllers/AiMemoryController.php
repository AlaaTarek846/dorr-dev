<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiMemoryRequest;
use Modules\AI\Http\Requests\AiMemorySettingsRequest;
use Modules\AI\Http\Resources\AiMemoryResource;
use Modules\AI\Services\AiMemoryService;

/**
 * The person's DORR AI memory (spec 140–144, 318): see what is kept, add, edit, delete one or all,
 * or switch memory off. Only their own — found by owner, never by someone else's id.
 */
class AiMemoryController extends Controller
{
    public function __construct(protected AiMemoryService $memory) {}

    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        return ApiResponse::success([
            'enabled' => $this->memory->enabled($owner),
            'limit' => AiMemoryService::MAX_MEMORIES,
            'memories' => AiMemoryResource::collection($this->memory->list($owner)),
        ], __('api.retrieved'));
    }

    public function store(AiMemoryRequest $request): JsonResponse
    {
        return ApiResponse::created(new AiMemoryResource($this->memory->remember($this->owner($request), $request->validated('content'))), __('ai.assistant_memory_saved'));
    }

    public function update(AiMemoryRequest $request, int $memory): JsonResponse
    {
        return ApiResponse::success(new AiMemoryResource($this->memory->update($this->owner($request), $memory, $request->validated('content'))), __('api.updated'));
    }

    public function destroy(Request $request, int $memory): JsonResponse
    {
        $this->memory->delete($this->owner($request), $memory);

        return ApiResponse::noContent(__('api.deleted'));
    }

    public function destroyAll(Request $request): JsonResponse
    {
        return ApiResponse::success(['deleted' => $this->memory->deleteAll($this->owner($request))], __('api.deleted'));
    }

    public function settings(AiMemorySettingsRequest $request): JsonResponse
    {
        $this->memory->setEnabled($this->owner($request), $request->boolean('enabled'));

        return ApiResponse::success(['enabled' => $request->boolean('enabled')], __('api.updated'));
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
