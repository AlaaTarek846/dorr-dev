<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\CatalogController;
use App\Http\Requests\General\LegalPageRequest;
use App\Services\General\LegalPageService;

class LegalPageController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'legal-page';
    }

    public function __construct(LegalPageService $service)
    {
        parent::__construct($service);
    }

    public function store(LegalPageRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(LegalPageRequest $request, int|string $legal_page)
    {
        return $this->service->updateRecord($legal_page, $request->validated());
    }

    public function deleteMultiple(LegalPageRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(LegalPageRequest $request, int|string $legal_page)
    {
        return $this->service->changeStatus($legal_page, (bool) $request->validated('status'));
    }
}