<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Http\Requests\General\PlatformSettingUpdateRequest;
use App\Services\General\PlatformSettingService;
use App\Support\Admin\AdminPermissionMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;

class PlatformSettingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('platform_settings', [
            ['view', ['show']],
            ['update', ['update']],
        ]);
    }

    public function __construct(protected PlatformSettingService $service) {}

    public function branding()
    {
        return $this->service->getBranding();
    }

    public function show()
    {
        return $this->service->getSettings();
    }

    public function update(PlatformSettingUpdateRequest $request)
    {
        return $this->service->updateSettings($request);
    }
}
