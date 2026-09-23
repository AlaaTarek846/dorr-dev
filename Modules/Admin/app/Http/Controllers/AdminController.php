<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Concerns\DefinesAdminCatalogPermissions;
use App\Http\Controllers\Controller;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\Admin\Http\Requests\AdminRequest;
use Modules\Admin\Services\AdminService;

class AdminController extends Controller implements HasMiddleware
{
    use DefinesAdminCatalogPermissions;

    protected static function adminPermissionGroup(): string
    {
        return 'admins';
    }

    protected static function permissionsOnDropdown(): bool
    {
        return false;
    }

    public function __construct(protected AdminService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AdminRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int|string $admin)
    {
        return $this->service->find($admin);
    }

    public function update(AdminRequest $request, int|string $admin)
    {
        return $this->service->updateRecord($admin, $request->validated());
    }

    public function destroy(int|string $admin)
    {
        return $this->service->delete($admin);
    }

    public function restore(int|string $admin)
    {
        return $this->service->restoreRecord($admin);
    }

    public function forceDestroy(int|string $admin)
    {
        return $this->service->forceDeleteRecord($admin);
    }

    public function deleteMultiple(AdminRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AdminRequest $request, int|string $admin)
    {
        return $this->service->changeStatus($admin, (bool) $request->validated('status'));
    }
}
