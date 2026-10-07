<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiSubscriptionRequest;
use Modules\AI\Services\AiSubscriptionService;

class AiSubscriptionController extends Controller
{
    public function __construct(protected AiSubscriptionService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiSubscriptionRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $subscription)
    {
        return $this->service->find($subscription);
    }

    public function update(AiSubscriptionRequest $request, int $subscription)
    {
        return $this->service->updateRecord($subscription, $request->validated());
    }

    public function destroy(int $subscription)
    {
        return $this->service->delete($subscription);
    }
}
