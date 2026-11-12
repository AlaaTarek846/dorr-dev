<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use Modules\AI\Http\Requests\AiLanguageVariantRequest;
use Modules\AI\Services\AiLanguageVariantService;

/**
 * Admin AI language variants - the catalog CRUD (like CountryController): index/show, store, update, status, bulk
 * delete, trash/restore/force delete, and the variant picker (dropdown) of the language evaluations form and the
 * user language preferences screen.
 */
class AiLanguageVariantController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-language-variants';
    }

    /**
     * Guarded by admin.screen on the route group (AiAdminScreens::middleware('ai-language-variants')), which also
     * lets the evaluations and user-preferences screens read this list - so no controller permission middleware here.
     *
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(AiLanguageVariantService $service)
    {
        parent::__construct($service);
    }

    public function store(AiLanguageVariantRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiLanguageVariantRequest $request, int|string $variant)
    {
        return $this->service->updateRecord($variant, $request->validated());
    }

    public function deleteMultiple(AiLanguageVariantRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiLanguageVariantRequest $request, int|string $variant)
    {
        return $this->service->changeStatus($variant, (bool) $request->validated('status'));
    }
}
