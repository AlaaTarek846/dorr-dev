<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\CatalogController;
use App\Http\Requests\General\ServiceCategoryRequest;
use App\Services\General\ServiceCategoryService;

class ServiceCategoryController extends CatalogController
{
    public function __construct(ServiceCategoryService $service)
    {
        parent::__construct($service);
    }

    public function tree()
    {
        return $this->service->tree();
    }

    public function leafOptions()
    {
        return $this->service->leafOptions();
    }

    public function store(ServiceCategoryRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(ServiceCategoryRequest $request, int|string $serviceCategory)
    {
        return $this->service->updateRecord($serviceCategory, $request->validated());
    }

    public function deleteMultiple(ServiceCategoryRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(ServiceCategoryRequest $request, int|string $serviceCategory)
    {
        return $this->service->changeStatus($serviceCategory, (bool) $request->validated('status'));
    }
}
