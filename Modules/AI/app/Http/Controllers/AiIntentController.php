<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiIntentRequest;
use Modules\AI\Services\AiIntentService;

class AiIntentController extends Controller
{
    public function __construct(protected AiIntentService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiIntentRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $intent)
    {
        return $this->service->find($intent);
    }

    public function update(AiIntentRequest $request, int $intent)
    {
        return $this->service->updateRecord($intent, $request->validated());
    }

    public function destroy(int $intent)
    {
        return $this->service->delete($intent);
    }
}
