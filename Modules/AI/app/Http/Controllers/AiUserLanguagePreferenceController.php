<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiUserLanguagePreferenceUpdateRequest;
use Modules\AI\Services\AiUserLanguagePreferenceService;

class AiUserLanguagePreferenceController extends Controller
{
    public function __construct(protected AiUserLanguagePreferenceService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $preference)
    {
        return $this->service->find($preference);
    }

    public function update(AiUserLanguagePreferenceUpdateRequest $request, int $preference)
    {
        return $this->service->updateRecord($preference, $request->validated());
    }
}
