<?php

namespace App\Http\Controllers;

use App\Http\Requests\FlagRequest;
use App\Services\FlagService;

class FlagController extends CatalogController
{
    public function __construct(FlagService $service)
    {
        parent::__construct($service);
    }

    public function store(FlagRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(FlagRequest $request, int|string $flag)
    {
        return $this->service->updateRecord($flag, $request->validated());
    }

    public function deleteMultiple(FlagRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(FlagRequest $request, int|string $flag)
    {
        return $this->service->changeStatus($flag, (bool) $request->validated('status'));
    }
}
