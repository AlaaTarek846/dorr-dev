<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiSafetyPolicyRequest;
use Modules\AI\Services\AiSafetyPolicyService;

class AiSafetyPolicyController extends Controller
{
    public function __construct(protected AiSafetyPolicyService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiSafetyPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $policy)
    {
        return $this->service->find($policy);
    }

    public function update(AiSafetyPolicyRequest $request, int $policy)
    {
        return $this->service->updateRecord($policy, $request->validated());
    }

    public function destroy(int $policy)
    {
        return $this->service->delete($policy);
    }
}
