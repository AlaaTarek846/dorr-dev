<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiRoutingPolicyRequest;
use Modules\AI\Services\AiRoutingPolicyService;

class AiRoutingPolicyController extends Controller
{
    public function __construct(protected AiRoutingPolicyService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiRoutingPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $policy)
    {
        return $this->service->find($policy);
    }

    public function update(AiRoutingPolicyRequest $request, int $policy)
    {
        return $this->service->updateRecord($policy, $request->validated());
    }

    public function destroy(int $policy)
    {
        return $this->service->delete($policy);
    }
}
