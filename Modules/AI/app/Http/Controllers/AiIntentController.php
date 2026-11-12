<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiIntentRequest;
use Modules\AI\Services\AiIntentService;

/**
 * Admin AI intents - the catalog CRUD (like CountryController): index/show, store, update, status, bulk delete,
 * trash/restore/force delete, and the intent picker (dropdown) of the routing rules form.
 */
class AiIntentController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-intents';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-intents')), which also lets the
     * routing rules screen read this list - so no controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiIntentService $service)
    {
        parent::__construct($service);
    }

    public function store(AiIntentRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiIntentRequest $request, int|string $intent)
    {
        return $this->service->updateRecord($intent, $request->validated());
    }

    public function deleteMultiple(AiIntentRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiIntentRequest $request, int|string $intent)
    {
        return $this->service->changeStatus($intent, (bool) $request->validated('status'));
    }
}
