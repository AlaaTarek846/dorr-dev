<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiFeatureFlagRequest;
use Modules\AI\Services\AiFeatureFlagService;

class AiFeatureFlagController extends Controller
{
    public function __construct(protected AiFeatureFlagService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiFeatureFlagRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $flag)
    {
        return $this->service->find($flag);
    }

    public function update(AiFeatureFlagRequest $request, int $flag)
    {
        return $this->service->updateRecord($flag, $request->validated());
    }

    public function destroy(int $flag)
    {
        return $this->service->delete($flag);
    }
}
