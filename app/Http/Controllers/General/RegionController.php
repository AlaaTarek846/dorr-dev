<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\CatalogController;
use App\Http\Requests\General\RegionRequest;
use App\Services\General\RegionService;

class RegionController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'regions';
    }

    public function __construct(RegionService $service)
    {
        parent::__construct($service);
    }

    public function store(RegionRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(RegionRequest $request, int|string $region)
    {
        return $this->service->updateRecord($region, $request->validated());
    }

    public function deleteMultiple(RegionRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(RegionRequest $request, int|string $region)
    {
        return $this->service->changeStatus($region, (bool) $request->validated('status'));
    }
}
