<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiRoutingRuleRequest;
use Modules\AI\Services\AiRoutingRuleService;

/**
 * Admin AI routing rules - the catalog CRUD (like CountryController, no translations): index/show, store, update,
 * status, bulk delete, trash/restore/force delete.
 */
class AiRoutingRuleController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-routing-rules';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-routing-rules')) - so no
     * controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiRoutingRuleService $service)
    {
        parent::__construct($service);
    }

    public function store(AiRoutingRuleRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiRoutingRuleRequest $request, int|string $rule)
    {
        return $this->service->updateRecord($rule, $request->validated());
    }

    public function deleteMultiple(AiRoutingRuleRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiRoutingRuleRequest $request, int|string $rule)
    {
        return $this->service->changeStatus($rule, (bool) $request->validated('status'));
    }
}
