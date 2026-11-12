<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\AI\Http\Requests\AiProviderUpdateRequest;
use Modules\AI\Services\AiProviderService;

class AiProviderController extends Controller implements HasMiddleware
{
    /**
     * Changing a provider needs ai-settings.update. Reading stays open to any admin: the feature-flag, routing-rule and
     * provider-data-rule forms fill their provider/model dropdowns from it (the key itself is never returned).
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ai-settings', [
            ['update', ['update', 'testConnection', 'setDefault']],
        ]);
    }

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
