<?php

namespace Modules\Provider\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Enums\UserStatus;
use Modules\Provider\Http\Requests\ProviderRequest;
use Modules\Provider\Services\ProvidersService;

class ProviderController extends Controller
{
    public function __construct(protected ProvidersService $service) {}

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
