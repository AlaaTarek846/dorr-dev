<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiBenchmarkCaseRequest;
use Modules\AI\Services\AiBenchmarkCaseService;

/**
 * Admin AI benchmark cases - the catalog CRUD (like CountryController, no translations): index/show, store, update,
 * status, bulk delete, trash/restore/force delete.
 */
class AiBenchmarkCaseController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-benchmark-cases';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-benchmark-cases')) - so no
     * controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiBenchmarkCaseService $service)
    {
        parent::__construct($service);
    }

    public function store(AiBenchmarkCaseRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiBenchmarkCaseRequest $request, int|string $case)
    {
        return $this->service->updateRecord($case, $request->validated());
    }

    public function deleteMultiple(AiBenchmarkCaseRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiBenchmarkCaseRequest $request, int|string $case)
    {
        return $this->service->changeStatus($case, (bool) $request->validated('status'));
    }
}
