<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiLanguageRequest;
use Modules\AI\Services\AiLanguageService;

class AiLanguageController extends Controller
{
    public function __construct(protected AiLanguageService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiLanguageRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $language)
    {
        return $this->service->find($language);
    }

    public function update(AiLanguageRequest $request, int $language)
    {
        return $this->service->updateRecord($language, $request->validated());
    }

    public function destroy(int $language)
    {
        return $this->service->delete($language);
    }
}
