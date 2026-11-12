<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiLanguageEvaluationRequest;
use Modules\AI\Services\AiLanguageEvaluationService;

/**
 * Admin AI language evaluations - the catalog CRUD (like CountryController): index/show, store, update, status, bulk
 * delete, trash/restore/force delete.
 */
class AiLanguageEvaluationController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-language-evaluations';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-language-evaluations')) - so no
     * controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiLanguageEvaluationService $service)
    {
        parent::__construct($service);
    }

    public function store(AiLanguageEvaluationRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiLanguageEvaluationRequest $request, int|string $evaluation)
    {
        return $this->service->updateRecord($evaluation, $request->validated());
    }

    public function deleteMultiple(AiLanguageEvaluationRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiLanguageEvaluationRequest $request, int|string $evaluation)
    {
        return $this->service->changeStatus($evaluation, (bool) $request->validated('status'));
    }
}
