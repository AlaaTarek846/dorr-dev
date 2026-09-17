<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\CatalogController;
use App\Http\Requests\General\LanguageRequest;
use App\Services\General\LanguageService;

class LanguageController extends CatalogController
{
    public function __construct(LanguageService $service)
    {
        parent::__construct($service);
    }

    public function store(LanguageRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(LanguageRequest $request, int|string $language)
    {
        return $this->service->updateRecord($language, $request->validated());
    }

    public function deleteMultiple(LanguageRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(LanguageRequest $request, int|string $language)
    {
        return $this->service->changeStatus($language, (bool) $request->validated('status'));
    }
}
