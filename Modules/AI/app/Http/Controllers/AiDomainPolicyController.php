<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiDomainPolicyRequest;
use Modules\AI\Services\AiDomainPolicyService;

/**
 * Admin AI domain policies - the catalog CRUD (like CountryController): index/show, store, update, status, bulk
 * delete, trash/restore/force delete.
 */
class AiDomainPolicyController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-domain-policies';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-domain-policies')) - so no
     * controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiDomainPolicyService $service)
    {
        parent::__construct($service);
    }

    public function store(AiDomainPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiDomainPolicyRequest $request, int|string $policy)
    {
        return $this->service->updateRecord($policy, $request->validated());
    }

    public function deleteMultiple(AiDomainPolicyRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiDomainPolicyRequest $request, int|string $policy)
    {
        return $this->service->changeStatus($policy, (bool) $request->validated('status'));
    }
}
