<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiPlanRequest;
use Modules\AI\Services\AiPlanService;

/**
 * Admin AI plans - the catalog CRUD (like CountryController): index/show, store, update, status, bulk delete,
 * trash/restore/force delete, each behind its ai-plans.* permission.
 */
class AiPlanController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-plans';
    }

    /** The plan picker in other AI screens (routing policies) works for any admin, like the countries dropdown. */
    protected static function permissionsOnDropdown(): bool
    {
        return false;
    }

    public function __construct(AiPlanService $service)
    {
        parent::__construct($service);
    }

    public function store(AiPlanRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiPlanRequest $request, int|string $plan)
    {
        return $this->service->updateRecord($plan, $request->validated());
    }

    public function deleteMultiple(AiPlanRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiPlanRequest $request, int|string $plan)
    {
        return $this->service->changeStatus($plan, (bool) $request->validated('status'));
    }
}
