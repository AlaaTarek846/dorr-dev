<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Http\Requests\General\PlatformSettingUpdateRequest;
use App\Services\General\PlatformSettingService;

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
