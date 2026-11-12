<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiGatewayRequest;
use Modules\AI\Services\AiGatewayService;

/**
 * Admin AI gateways - the catalog CRUD (like CountryController): index/show, store, update, status, bulk delete,
 * trash/restore/force delete.
 */
class AiGatewayController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-gateways';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-gateways')) - so no controller
     * permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiGatewayService $service)
    {
        parent::__construct($service);
    }

    public function store(AiGatewayRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiGatewayRequest $request, int|string $gateway)
    {
        return $this->service->updateRecord($gateway, $request->validated());
    }

    public function deleteMultiple(AiGatewayRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiGatewayRequest $request, int|string $gateway)
    {
        return $this->service->changeStatus($gateway, (bool) $request->validated('status'));
    }
}
