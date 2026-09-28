<?php

namespace Modules\Sms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Sms\Http\Requests\SmsProviderRequest;
use Modules\Sms\Http\Resources\SmsProviderResource;
use Modules\Sms\Services\Sms\SmsAdapterRegistry;
use Modules\Sms\Services\Sms\SmsProviderService;

/**
 * Provider endpoints are thin: they validate input, hand a raw id to the
 * service, and wrap the result in the API envelope. Module routes do not run
 * Laravel's SubstituteBindings, so an id is passed and resolved in the service
 * rather than relying on implicit model binding.
 */
class SmsProviderController extends Controller implements HasMiddleware
{
    public function __construct(protected SmsProviderService $service) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('sms-providers', [
            ['view', ['index', 'show', 'dropdown', 'types']],
            ['create', ['store']],
            ['update', ['update']],
            ['delete', ['destroy']],
            ['change-status', ['toggleActive']],
            ['multiple-delete', ['deleteMultiple']],
            ['test', ['test', 'testDraft']],
        ]);
    }

    public function index(Request $request)
    {
        return ApiResponse::paginated(
            $this->service->query($request),
            SmsProviderResource::class,
        );
    }

    public function store(SmsProviderRequest $request)
    {
        return ApiResponse::created(
            new SmsProviderResource($this->service->create($request->validated())),
            __('sms.providers.created'),
        );
    }

    public function show(int|string $sms_provider)
    {
        return ApiResponse::success(
            new SmsProviderResource($this->service->findOrFail($sms_provider)->load('smsAccounts')),
            __('sms.providers.fetched'),
        );
    }

    public function update(SmsProviderRequest $request, int|string $sms_provider)
    {
        return ApiResponse::success(
            new SmsProviderResource($this->service->update($sms_provider, $request->validated())),
            __('sms.providers.updated'),
        );
    }

    public function destroy(int|string $sms_provider)
    {
        $this->service->delete($sms_provider);

        return ApiResponse::success([], __('sms.providers.deleted'));
    }

    public function deleteMultiple(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:sms_providers,id'],
        ]);

        $this->service->deleteMultiple($validated['ids']);

        return ApiResponse::success([], __('sms.providers.deleted'));
    }

    public function toggleActive(int|string $sms_provider)
    {
        $isActive = $this->service->toggleActive($sms_provider);

        return ApiResponse::success(
            ['is_active' => $isActive],
            $isActive ? __('sms.providers.activated') : __('sms.providers.deactivated'),
        );
    }

    public function dropdown()
    {
        return ApiResponse::success($this->service->dropdown());
    }

    public function types()
    {
        return ApiResponse::success($this->service->types());
    }

    public function test(int|string $sms_provider)
    {
        return ApiResponse::success($this->service->readiness($sms_provider));
    }

    public function testDraft(Request $request)
    {
        $validated = $request->validate([
            'key' => [
                'required',
                'string',
                'max:80',
                Rule::in(app(SmsAdapterRegistry::class)->keys()),
            ],
            'configuration' => ['required', 'array'],
            'provider_id' => ['nullable', 'integer', 'exists:sms_providers,id'],
        ]);

        return ApiResponse::success(
            $this->service->testDraft(
                $validated['key'],
                $validated['configuration'],
                $validated['provider_id'] ?? null,
            ),
            __('sms.providers.connection_successful'),
        );
    }
}
