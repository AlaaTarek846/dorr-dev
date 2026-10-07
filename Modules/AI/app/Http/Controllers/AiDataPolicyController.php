<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiDataPolicyRequest;
use Modules\AI\Services\AiDataPolicyService;

class AiDataPolicyController extends Controller
{
    public function __construct(protected AiDataPolicyService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiDataPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $policy)
    {
        return $this->service->find($policy);
    }

    public function update(AiDataPolicyRequest $request, int $policy)
    {
        return $this->service->updateRecord($policy, $request->validated());
    }

    public function destroy(int $policy)
    {
        return $this->service->delete($policy);
    }
}
