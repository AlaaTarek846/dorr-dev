<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiLearnedIntentRequest;
use Modules\AI\Services\AiLearnedIntentAdminService;

/**
 * Admin learned phrases of the smart intent router - the catalog CRUD (like CountryController, no translations):
 * index/show, store, update, status, bulk delete, trash/restore/force delete - plus the screen's stats and the
 * side-effect-free "try a phrase" tester.
 */
class AiLearnedIntentController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-learned-intents';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-learned-intents')): GET = view,
     * the tester (a POST) = update - so no controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiLearnedIntentAdminService $service)
    {
        parent::__construct($service);
    }

    public function stats(): JsonResponse
    {
        return $this->service->stats();
    }

    public function store(AiLearnedIntentRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiLearnedIntentRequest $request, int|string $learnedIntent)
    {
        return $this->service->updateRecord($learnedIntent, $request->validated());
    }

    public function deleteMultiple(AiLearnedIntentRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiLearnedIntentRequest $request, int|string $learnedIntent)
    {
        return $this->service->changeStatus($learnedIntent, (bool) $request->validated('status'));
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
