<?php

namespace App\Http\Controllers;

use App\Http\Requests\CountryRequest;
use App\Services\CountryService;

class CountryController extends CatalogController
{
    public function __construct(CountryService $service)
    {
        parent::__construct($service);
    }

    public function store(CountryRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(CountryRequest $request, int|string $country)
    {
        return $this->service->updateRecord($country, $request->validated());
    }

    public function deleteMultiple(CountryRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(CountryRequest $request, int|string $country)
    {
        return $this->service->changeStatus($country, (bool) $request->validated('status'));
    }
}
