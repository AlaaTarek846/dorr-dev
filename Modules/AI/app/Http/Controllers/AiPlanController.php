<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiPlanRequest;
use Modules\AI\Services\AiPlanService;

class AiPlanController extends Controller
{
    public function __construct(protected AiPlanService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiPlanRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $plan)
    {
        return $this->service->find($plan);
    }

    public function update(AiPlanRequest $request, int $plan)
    {
        return $this->service->updateRecord($plan, $request->validated());
    }

    public function destroy(int $plan)
    {
        return $this->service->delete($plan);
    }
}
