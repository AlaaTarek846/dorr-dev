<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\Admin\Http\Requests\RoleRequest;
use Modules\Admin\Services\RoleService;

class RoleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::apiResourceWithBulkDelete('roles');
    }

    public function __construct(protected RoleService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(RoleRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int|string $role)
    {
        return $this->service->find($role);
    }

    public function update(RoleRequest $request, int|string $role)
    {
        return $this->service->updateRecord($role, $request->validated());
    }

    public function destroy(int|string $role)
    {
        return $this->service->delete($role);
    }

    public function deleteMultiple(RoleRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }
}
