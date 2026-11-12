<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiSafetyRuleRequest;
use Modules\AI\Services\AiSafetyRuleService;

/**
 * Admin AI safety rules - the catalog CRUD (like CountryController): index/show, store, update, status, bulk delete,
 * trash/restore/force delete.
 */
class AiSafetyRuleController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-safety-rules';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-safety-rules')) - so no
     * controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiSafetyRuleService $service)
    {
        parent::__construct($service);
    }

    public function store(AiSafetyRuleRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiSafetyRuleRequest $request, int|string $rule)
    {
        return $this->service->updateRecord($rule, $request->validated());
    }

    public function deleteMultiple(AiSafetyRuleRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiSafetyRuleRequest $request, int|string $rule)
    {
        return $this->service->changeStatus($rule, (bool) $request->validated('status'));
    }
}
