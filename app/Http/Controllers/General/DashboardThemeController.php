<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Concerns\DefinesAdminCatalogPermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\General\DashboardThemeRequest;
use App\Services\General\DashboardThemeService;
use Illuminate\Routing\Controllers\HasMiddleware;

class DashboardThemeController extends Controller implements HasMiddleware
{
    use DefinesAdminCatalogPermissions;

    protected static function adminPermissionGroup(): string
    {
        return 'dashboard_themes';
    }

    protected static function permissionsOnDropdown(): bool
    {
        return false;
    }

    public function __construct(protected DashboardThemeService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(DashboardThemeRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int|string $dashboard_theme)
    {
        return $this->service->find($dashboard_theme);
    }

    public function update(DashboardThemeRequest $request, int|string $dashboard_theme)
    {
        return $this->service->updateRecord($dashboard_theme, $request->validated());
    }

    public function destroy(int|string $dashboard_theme)
    {
        return $this->service->delete($dashboard_theme);
    }

    public function restore(int|string $dashboard_theme)
    {
        return $this->service->restoreRecord($dashboard_theme);
    }

    public function forceDestroy(int|string $dashboard_theme)
    {
        return $this->service->forceDeleteRecord($dashboard_theme);
    }

    public function deleteMultiple(DashboardThemeRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(DashboardThemeRequest $request, int|string $dashboard_theme)
    {
        return $this->service->changeStatus(
            $dashboard_theme,
            (bool) $request->validated('status'),
        );
    }
}
