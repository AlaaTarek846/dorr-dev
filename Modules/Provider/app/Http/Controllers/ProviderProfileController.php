<?php

namespace Modules\Provider\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Provider\Http\Requests\ProviderRejectRequest;
use Modules\Provider\Http\Requests\ProviderServiceAssignRequest;
use Modules\Provider\Services\ProviderProfileService;

class ProviderProfileController extends Controller
{
    public function __construct(protected ProviderProfileService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int|string $provider)
    {
        return $this->service->find($provider);
    }

    public function approve(Request $request, int|string $provider)
    {
        return $this->service->approve($provider, $request->user('admin_api'));
    }

    public function reject(ProviderRejectRequest $request, int|string $provider)
    {
        return $this->service->reject($provider, $request->validated('reason'));
    }

    public function suspend(int|string $provider)
    {
        return $this->service->suspend($provider);
    }

    public function reactivate(int|string $provider)
    {
        return $this->service->reactivate($provider);
    }

    public function addService(ProviderServiceAssignRequest $request, int|string $provider)
    {
        return $this->service->addService($provider, (int) $request->validated('service_category_id'));
    }

    public function approveService(int|string $provider, int|string $service)
    {
        return $this->service->approveService($provider, $service);
    }

    public function rejectService(int|string $provider, int|string $service)
    {
        return $this->service->rejectService($provider, $service);
    }
}
