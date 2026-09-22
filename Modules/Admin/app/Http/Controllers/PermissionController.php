<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Admin\Http\Requests\PermissionRequest;
use Modules\Admin\Services\PermissionService;

class PermissionController extends Controller
{
    public function __construct(protected PermissionService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(PermissionRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int|string $permission)
    {
        return $this->service->find($permission);
    }

    public function update(PermissionRequest $request, int|string $permission)
    {
        return $this->service->updateRecord($permission, $request->validated());
    }

    public function destroy(int|string $permission)
    {
        return $this->service->delete($permission);
    }

    public function deleteMultiple(PermissionRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }
}
