<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\DefinesAdminCatalogPermissions;
use App\Http\Requests\General\MobileAppColorDefaultRequest;
use App\Services\General\MobileAppColorDefaultService;
use Illuminate\Routing\Controllers\HasMiddleware;

class MobileAppColorDefaultController extends Controller implements HasMiddleware
{
    use DefinesAdminCatalogPermissions;

    protected static function adminPermissionGroup(): string
    {
        return 'mobile_app_color_defaults';
    }

    public function __construct(protected MobileAppColorDefaultService $service) {}

    public function show()
    {
        return $this->service->getSettings();
    }

    public function update(MobileAppColorDefaultRequest $request)
    {
        return $this->service->updateSettings($request->validated());
    }
}
