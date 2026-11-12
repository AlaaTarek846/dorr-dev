<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiSecurityPolicyRequest;
use Modules\AI\Services\AiSecurityPolicyService;

/**
 * Admin AI security policies - the catalog CRUD (like CountryController): index/show, store, update, status, bulk
 * delete, trash/restore/force delete.
 */
class AiSecurityPolicyController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-security-policies';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-security-policies')) - so no controller
     * permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiSecurityPolicyService $service)
    {
        parent::__construct($service);
    }

    public function store(AiSecurityPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiSecurityPolicyRequest $request, int|string $policy)
    {
        return $this->service->updateRecord($policy, $request->validated());
    }

    public function deleteMultiple(AiSecurityPolicyRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiSecurityPolicyRequest $request, int|string $policy)
    {
        return $this->service->changeStatus($policy, (bool) $request->validated('status'));
    }
}
