<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\CatalogController;
use App\Http\Requests\General\CurrencyRequest;
use App\Services\General\CurrencyService;

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

    public function syncExchangeRates()
    {
        return $this->service->syncExchangeRates();
    }
}
