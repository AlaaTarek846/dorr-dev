<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiRoutingRuleRequest;
use Modules\AI\Services\AiRoutingRuleService;

class AiRoutingRuleController extends Controller
{
    public function __construct(protected AiRoutingRuleService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiRoutingRuleRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $rule)
    {
        return $this->service->find($rule);
    }

    public function update(AiRoutingRuleRequest $request, int $rule)
    {
        return $this->service->updateRecord($rule, $request->validated());
    }

    public function destroy(int $rule)
    {
        return $this->service->delete($rule);
    }
}
