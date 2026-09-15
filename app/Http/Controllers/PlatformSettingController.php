<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlatformSettingUpdateRequest;
use App\Services\PlatformSettingService;

class PlatformSettingController extends Controller
{
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
