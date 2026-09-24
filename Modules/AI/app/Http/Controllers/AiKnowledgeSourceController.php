<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Modules\AI\Http\Requests\AiKnowledgeSourceIngestRequest;
use Modules\AI\Http\Resources\AiKnowledgeSourceResource;
use Modules\AI\Services\AiKnowledgeSourceService;

class AiKnowledgeSourceController extends Controller
{
    public function __construct(protected AiKnowledgeSourceService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $source)
    {
        return $this->service->find($source);
    }

    public function store(AiKnowledgeSourceIngestRequest $request)
    {
        $source = $this->service->ingest([
            ...$request->validated(),
            'owner_type' => 'system',
            'owner_id' => null,
        ]);

        return ApiResponse::created(new AiKnowledgeSourceResource($source->load('chunks')), __('api.created'));
    }

    public function approve(int $source)
    {
        return ApiResponse::success(new AiKnowledgeSourceResource($this->service->approve($source)), __('api.updated'));
    }

    public function reject(int $source)
    {
        return ApiResponse::success(new AiKnowledgeSourceResource($this->service->reject($source)), __('api.updated'));
    }

    public function deprecate(int $source)
    {
        return ApiResponse::success(new AiKnowledgeSourceResource($this->service->deprecate($source)), __('api.updated'));
    }
}
