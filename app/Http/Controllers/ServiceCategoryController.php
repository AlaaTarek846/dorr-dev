<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceCategoryRequest;
use App\Services\ServiceCategoryService;

class ServiceCategoryController extends Controller
{
    public function __construct(protected ServiceCategoryService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function tree()
    {
        return $this->service->tree();
    }

    public function dropdown()
    {
        return $this->service->dropdown();
    }

    public function leafOptions()
    {
        return $this->service->leafOptions();
    }

    public function store(ServiceCategoryRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int|string $serviceCategory)
    {
        return $this->service->find($serviceCategory);
    }

    public function update(ServiceCategoryRequest $request, int|string $serviceCategory)
    {
        return $this->service->updateRecord($serviceCategory, $request->validated());
    }

    public function destroy(int|string $serviceCategory)
    {
        return $this->service->delete($serviceCategory);
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
