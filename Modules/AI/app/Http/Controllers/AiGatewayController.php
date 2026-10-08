<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiGatewayRequest;
use Modules\AI\Services\AiGatewayService;

class AiGatewayController extends Controller
{
    public function __construct(protected AiGatewayService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiGatewayRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $gateway)
    {
        return $this->service->find($gateway);
    }

    public function update(AiGatewayRequest $request, int $gateway)
    {
        return $this->service->updateRecord($gateway, $request->validated());
    }

    public function destroy(int $gateway)
    {
        return $this->service->delete($gateway);
    }
}
