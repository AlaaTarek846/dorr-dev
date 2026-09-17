<?php

namespace App\Http\Controllers;

use App\Services\CatalogService;

abstract class CatalogController extends Controller
{
    public function __construct(protected CatalogService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int|string $id)
    {
        return $this->service->find($id);
    }

    public function destroy(int|string $id)
    {
        return $this->service->delete($id);
    }

    public function restore(int|string $id)
    {
        return $this->service->restoreRecord($id);
    }

    public function forceDestroy(int|string $id)
    {
        return $this->service->forceDeleteRecord($id);
    }

    public function dropdown()
    {
        return $this->service->dropdown();
    }
}
