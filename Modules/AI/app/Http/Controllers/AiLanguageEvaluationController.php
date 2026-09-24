<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiLanguageEvaluationRequest;
use Modules\AI\Services\AiLanguageEvaluationService;

class AiLanguageEvaluationController extends Controller
{
    public function __construct(protected AiLanguageEvaluationService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiLanguageEvaluationRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $evaluation)
    {
        return $this->service->find($evaluation);
    }

    public function update(AiLanguageEvaluationRequest $request, int $evaluation)
    {
        return $this->service->updateRecord($evaluation, $request->validated());
    }

    public function destroy(int $evaluation)
    {
        return $this->service->delete($evaluation);
    }
}
