<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiDataPolicyRequest;
use Modules\AI\Services\AiDataPolicyService;

/**
 * Admin AI data policies - the catalog CRUD (like CountryController): index/show, store, update, status, bulk
 * delete, trash/restore/force delete.
 */
class AiDataPolicyController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-data-policies';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-data-policies')) - so no controller
     * permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiDataPolicyService $service)
    {
        parent::__construct($service);
    }

    public function store(AiDataPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiDataPolicyRequest $request, int|string $policy)
    {
        return $this->service->updateRecord($policy, $request->validated());
    }

    public function deleteMultiple(AiDataPolicyRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiDataPolicyRequest $request, int|string $policy)
    {
        return $this->service->changeStatus($policy, (bool) $request->validated('status'));
    }
}
