<?php

namespace Modules\Provider\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\Provider\Http\Requests\ProviderRequest;
use Modules\Provider\Services\ProvidersService;

/** The dashboard's providers list: each action needs its `providers.*` permission. */
class ProviderController extends Controller implements HasMiddleware
{
    public function __construct(protected ProvidersService $service) {}

    public static function middleware(): array
    {
        return AdminPermissionMiddleware::catalog('providers', secureDropdown: false);
    }

    public function index()
    {
        return $this->service->list();
    }

    public function store(ProviderRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int|string $provider)
    {
        return $this->service->find($provider);
    }

    public function update(ProviderRequest $request, int|string $provider)
    {
        return $this->service->updateRecord($provider, $request->validated());
    }

    public function destroy(int|string $provider)
    {
        return $this->service->delete($provider);
    }

    public function restore(int|string $provider)
    {
        return $this->service->restoreRecord($provider);
    }

    public function forceDestroy(int|string $provider)
    {
        return $this->service->forceDeleteRecord($provider);
    }

    public function deleteMultiple(ProviderRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(ProviderRequest $request, int|string $provider)
    {
        return $this->service->changeStatus(
            $provider,
            UserStatus::from($request->validated('status')),
        );
    }
}
