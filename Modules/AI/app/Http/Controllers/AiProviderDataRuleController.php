<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiProviderDataRuleRequest;
use Modules\AI\Services\AiProviderDataRuleService;

class AiProviderDataRuleController extends Controller
{
    public function __construct(protected AiProviderDataRuleService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiProviderDataRuleRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $providerDataRule)
    {
        return $this->service->find($providerDataRule);
    }

    public function update(AiProviderDataRuleRequest $request, int $providerDataRule)
    {
        return $this->service->updateRecord($providerDataRule, $request->validated());
    }

    public function destroy(int $providerDataRule)
    {
        return $this->service->delete($providerDataRule);
    }
}
