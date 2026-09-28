<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiProviderModelRequest;
use Modules\AI\Services\AiProviderModelService;

class AiProviderModelController extends Controller
{
    public function __construct(protected AiProviderModelService $service) {}

    public function index(string $provider)
    {
        return $this->service->list($provider);
    }

    public function store(AiProviderModelRequest $request, string $provider)
    {
        return $this->service->store($provider, $request->validated());
    }

    public function update(AiProviderModelRequest $request, string $provider, int $model)
    {
        return $this->service->update($provider, $model, $request->validated());
    }

    public function destroy(string $provider, int $model)
    {
        return $this->service->destroy($provider, $model);
    }

    public function reclassify(string $provider)
    {
        return $this->service->reclassify($provider);
    }

    public function sync(string $provider)
    {
        return $this->service->sync($provider);
    }

    public function normalize(string $provider)
    {
        return $this->service->normalize($provider);
    }
}
