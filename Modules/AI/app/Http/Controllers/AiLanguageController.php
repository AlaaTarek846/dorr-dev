<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiLanguageRequest;
use Modules\AI\Services\AiLanguageService;

/**
 * Root-cause fix (languages consolidation): used to be full CRUD over the
 * AI module's own now-removed "ai_languages" table. store()/destroy() are
 * gone on purpose - creating or deleting a language is the general
 * Languages admin screen's job (it also affects the website/dashboard),
 * so this screen only lists the platform's languages and toggles whether
 * each one is enabled for the AI assistant.
 */
class AiLanguageController extends Controller
{
    public function __construct(protected AiLanguageService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $language)
    {
        return $this->service->find($language);
    }

    public function update(AiLanguageRequest $request, int $language)
    {
        return $this->service->updateRecord($language, $request->validated());
    }
}
