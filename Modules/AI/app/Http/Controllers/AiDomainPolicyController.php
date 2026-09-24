<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiDomainPolicyRequest;
use Modules\AI\Services\AiDomainPolicyService;

class AiDomainPolicyController extends Controller
{
    public function __construct(protected AiDomainPolicyService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiDomainPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $policy)
    {
        return $this->service->find($policy);
    }

    public function update(AiDomainPolicyRequest $request, int $policy)
    {
        return $this->service->updateRecord($policy, $request->validated());
    }

    public function destroy(int $policy)
    {
        return $this->service->delete($policy);
    }
}
