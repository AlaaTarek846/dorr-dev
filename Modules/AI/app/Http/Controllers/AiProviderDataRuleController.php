<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiProviderDataRuleRequest;
use Modules\AI\Services\AiProviderDataRuleService;

/**
 * Admin AI provider data rules - the catalog CRUD (like CountryController, no translations): index/show, store, update, status, bulk
 * delete, trash/restore/force delete.
 */
class AiProviderDataRuleController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-provider-data-rules';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-provider-data-rules')) - so no controller
     * permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiProviderDataRuleService $service)
    {
        parent::__construct($service);
    }

    public function store(AiProviderDataRuleRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiProviderDataRuleRequest $request, int|string $rule)
    {
        return $this->service->updateRecord($rule, $request->validated());
    }

    public function deleteMultiple(AiProviderDataRuleRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiProviderDataRuleRequest $request, int|string $rule)
    {
        return $this->service->changeStatus($rule, (bool) $request->validated('status'));
    }
}
