<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiProviderUpdateRequest;
use Modules\AI\Services\AiProviderService;

class AiProviderController extends Controller
{
    public function __construct(protected AiProviderService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function update(AiProviderUpdateRequest $request, string $provider)
    {
        return $this->service->update($provider, $request->validated());
    }

    public function testConnection(string $provider)
    {
        return $this->service->testConnection($provider);
    }

    public function setDefault(string $provider)
    {
        return $this->service->setDefault($provider);
    }
}
