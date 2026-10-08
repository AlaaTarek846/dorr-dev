<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiSafetyRuleRequest;
use Modules\AI\Services\AiSafetyRuleService;

class AiSafetyRuleController extends Controller
{
    public function __construct(protected AiSafetyRuleService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiSafetyRuleRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $rule)
    {
        return $this->service->find($rule);
    }

    public function update(AiSafetyRuleRequest $request, int $rule)
    {
        return $this->service->updateRecord($rule, $request->validated());
    }

    public function destroy(int $rule)
    {
        return $this->service->delete($rule);
    }
}
