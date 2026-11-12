<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiProjectInstructionRequest;
use Modules\AI\Services\AiProjectInstructionService;

/**
 * Admin AI project instructions - the catalog CRUD (like CountryController, no translations): index/show, store, update, status, bulk
 * delete, trash/restore/force delete.
 */
class AiProjectInstructionController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-project-instructions';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-project-instructions')) - so no controller
     * permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiProjectInstructionService $service)
    {
        parent::__construct($service);
    }

    public function store(AiProjectInstructionRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiProjectInstructionRequest $request, int|string $instruction)
    {
        return $this->service->updateRecord($instruction, $request->validated());
    }

    public function deleteMultiple(AiProjectInstructionRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiProjectInstructionRequest $request, int|string $instruction)
    {
        return $this->service->changeStatus($instruction, (bool) $request->validated('status'));
    }
}
