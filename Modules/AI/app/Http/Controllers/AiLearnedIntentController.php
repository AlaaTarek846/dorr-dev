<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiLearnedIntentStoreRequest;
use Modules\AI\Http\Requests\AiLearnedIntentUpdateRequest;
use Modules\AI\Services\AiLearnedIntentAdminService;

class AiLearnedIntentController extends Controller
{
    public function __construct(protected AiLearnedIntentAdminService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->service->list($request->only(['status', 'intent', 'mode', 'source', 'search', 'per_page']));
    }

    public function stats(): JsonResponse
    {
        return $this->service->stats();
    }

    public function store(AiLearnedIntentStoreRequest $request): JsonResponse
    {
        return $this->service->create($request->validated());
    }

    public function update(AiLearnedIntentUpdateRequest $request, int $learnedIntent): JsonResponse
    {
        return $this->service->update($learnedIntent, $request->validated());
    }

    public function destroy(int $learnedIntent): JsonResponse
    {
        return $this->service->delete($learnedIntent);
    }

    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:600'],
            'recent_image' => ['sometimes', 'boolean'],
        ]);

        return $this->service->test($data['message'], (bool) ($data['recent_image'] ?? false));
    }
}
