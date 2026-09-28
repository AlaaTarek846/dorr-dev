<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\CatalogController;
use App\Http\Requests\General\MobileAppFontRequest;
use App\Services\General\MobileAppFontService;

class MobileAppFontController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'mobile_app_fonts';
    }

    public function __construct(MobileAppFontService $service)
    {
        parent::__construct($service);
    }

    public function store(MobileAppFontRequest $request)
    {
        return $this->service->create($this->payload($request));
    }

    public function update(MobileAppFontRequest $request, int|string $mobile_app_font)
    {
        return $this->service->updateRecord($mobile_app_font, $this->payload($request));
    }

    public function deleteMultiple(MobileAppFontRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(MobileAppFontRequest $request, int|string $mobile_app_font)
    {
        return $this->service->changeStatus($mobile_app_font, (bool) $request->validated('status'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(MobileAppFontRequest $request): array
    {
        $data = $request->validated();
        $data['font_files'] = $request->file('font_files', []);
        $data['remove_font_file_ids'] = $request->input('remove_font_file_ids', []);

        return $data;
    }
}
