<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiSecurityPolicyRequest;
use Modules\AI\Services\AiSecurityPolicyService;

class AiSecurityPolicyController extends Controller
{
    public function __construct(protected AiSecurityPolicyService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiSecurityPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $policy)
    {
        return $this->service->find($policy);
    }

    public function update(AiSecurityPolicyRequest $request, int $policy)
    {
        return $this->service->updateRecord($policy, $request->validated());
    }

    public function destroy(int $policy)
    {
        return $this->service->delete($policy);
    }
}
