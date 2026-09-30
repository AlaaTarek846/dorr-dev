<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\CatalogController;
use App\Http\Requests\General\FaqRequest;
use App\Services\General\FaqService;

class FaqController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'faqs';
    }

    public function __construct(FaqService $service)
    {
        parent::__construct($service);
    }

    public function store(FaqRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(FaqRequest $request, int|string $faq)
    {
        return $this->service->updateRecord($faq, $request->validated());
    }

    public function deleteMultiple(FaqRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(FaqRequest $request, int|string $faq)
    {
        return $this->service->changeStatus($faq, (bool) $request->validated('status'));
    }
}
