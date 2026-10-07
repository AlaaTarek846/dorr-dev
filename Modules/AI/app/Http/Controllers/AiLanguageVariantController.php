<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiLanguageVariantRequest;
use Modules\AI\Services\AiLanguageVariantService;

class AiLanguageVariantController extends Controller
{
    public function __construct(protected AiLanguageVariantService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiLanguageVariantRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $variant)
    {
        return $this->service->find($variant);
    }

    public function update(AiLanguageVariantRequest $request, int $variant)
    {
        return $this->service->updateRecord($variant, $request->validated());
    }

    public function destroy(int $variant)
    {
        return $this->service->delete($variant);
    }
}
