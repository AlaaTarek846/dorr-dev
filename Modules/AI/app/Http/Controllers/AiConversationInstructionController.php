<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiConversationInstructionRequest;
use Modules\AI\Services\AiConversationInstructionService;

/**
 * Admin AI conversation instructions - the catalog CRUD (like CountryController, no translations): index/show, store, update, status, bulk
 * delete, trash/restore/force delete.
 */
class AiConversationInstructionController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-conversation-instructions';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-conversation-instructions')) - so no controller
     * permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiConversationInstructionService $service)
    {
        parent::__construct($service);
    }

    public function store(AiConversationInstructionRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiConversationInstructionRequest $request, int|string $instruction)
    {
        return $this->service->updateRecord($instruction, $request->validated());
    }

    public function deleteMultiple(AiConversationInstructionRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiConversationInstructionRequest $request, int|string $instruction)
    {
        return $this->service->changeStatus($instruction, (bool) $request->validated('status'));
    }
}
