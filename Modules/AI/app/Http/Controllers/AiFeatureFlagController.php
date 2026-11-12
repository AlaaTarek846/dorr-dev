<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiFeatureFlagRequest;
use Modules\AI\Services\AiFeatureFlagService;

/**
 * Admin AI feature flags - the catalog CRUD (like CountryController): index/show, store, update, status, bulk delete,
 * trash/restore/force delete.
 */
class AiFeatureFlagController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-feature-flags';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-feature-flags')) - so no
     * controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiFeatureFlagService $service)
    {
        parent::__construct($service);
    }

    public function store(AiFeatureFlagRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiFeatureFlagRequest $request, int|string $flag)
    {
        return $this->service->updateRecord($flag, $request->validated());
    }

    public function deleteMultiple(AiFeatureFlagRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiFeatureFlagRequest $request, int|string $flag)
    {
        return $this->service->changeStatus($flag, (bool) $request->validated('status'));
    }
}
