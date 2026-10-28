<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiContinueConversationRequest;
use Modules\AI\Http\Requests\AiHistorySearchRequest;
use Modules\AI\Http\Resources\AiConversationResource;
use Modules\AI\Services\AiContextContinuationService;
use Modules\AI\Services\AiHistorySearchService;
use Modules\AI\Services\AiOutputGalleryService;

/**
 * The person's DORR AI history: search it by word and by meaning (spec 133–139), see everything
 * DORR AI made (spec 344), and continue earlier work in a new chat with its Context Capsule
 * (spec 121–132) — which they can read and delete.
 */
class AiHistoryController extends Controller
{
    public function __construct(
        protected AiHistorySearchService $search,
        protected AiOutputGalleryService $gallery,
        protected AiContextContinuationService $continuation,
    ) {}

    public function search(AiHistorySearchRequest $request): JsonResponse
    {
        return ApiResponse::success($this->search->search($this->owner($request), (string) $request->validated('q')), __('api.retrieved'));
    }

    public function outputs(AiHistorySearchRequest $request): JsonResponse
    {
        return ApiResponse::success($this->gallery->list($this->owner($request), $request->validated('kind')), __('api.retrieved'));
    }

    public function continue(AiContinueConversationRequest $request): JsonResponse
    {
        $conversation = $this->continuation->continueFrom($this->owner($request), $request->validated('conversation_ids'));

        return ApiResponse::created([
            'conversation' => new AiConversationResource($conversation),
            'capsule' => $this->continuation->show($this->owner($request), $conversation->id),
        ], __('ai.assistant_capsule_created'));
    }

    public function capsule(Request $request, int $conversation): JsonResponse
    {
        return ApiResponse::success(['capsule' => $this->continuation->show($this->owner($request), $conversation)], __('api.retrieved'));
    }

    public function destroyCapsule(Request $request, int $conversation): JsonResponse
    {
        $this->continuation->delete($this->owner($request), $conversation);

        return ApiResponse::noContent(__('api.deleted'));
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
