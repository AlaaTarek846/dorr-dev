<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiLocaleRequest;
use Modules\AI\Services\AiLocaleService;

class AiLocaleController extends Controller
{
    public function __construct(protected AiLocaleService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiLocaleRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $locale)
    {
        return $this->service->find($locale);
    }

    public function update(AiLocaleRequest $request, int $locale)
    {
        return $this->service->updateRecord($locale, $request->validated());
    }

    public function destroy(int $locale)
    {
        return $this->service->delete($locale);
    }
}
