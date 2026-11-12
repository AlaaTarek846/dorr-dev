<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiRoutingPolicyRequest;
use Modules\AI\Services\AiRoutingPolicyService;

/**
 * Admin AI routing policies - the catalog CRUD (like CountryController): index/show, store, update, status, bulk
 * delete, trash/restore/force delete, and the policy picker (dropdown) of the gateways and routing rules forms.
 */
class AiRoutingPolicyController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-routing-policies';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-routing-policies')), which also
     * lets the gateways and routing rules screens read this list - so no controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiRoutingPolicyService $service)
    {
        parent::__construct($service);
    }

    public function store(AiRoutingPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiRoutingPolicyRequest $request, int|string $policy)
    {
        return $this->service->updateRecord($policy, $request->validated());
    }

    public function deleteMultiple(AiRoutingPolicyRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiRoutingPolicyRequest $request, int|string $policy)
    {
        return $this->service->changeStatus($policy, (bool) $request->validated('status'));
    }
}
