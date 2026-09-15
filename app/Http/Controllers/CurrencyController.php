<?php

namespace App\Http\Controllers;

use App\Http\Requests\CurrencyRequest;
use App\Services\CurrencyService;

class CurrencyController extends CatalogController
{
    public function __construct(CurrencyService $service)
    {
        parent::__construct($service);
    }

    public function store(CurrencyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(CurrencyRequest $request, int|string $currency)
    {
        return $this->service->updateRecord($currency, $request->validated());
    }

    public function deleteMultiple(CurrencyRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(CurrencyRequest $request, int|string $currency)
    {
        return $this->service->changeStatus($currency, (bool) $request->validated('status'));
    }
}
