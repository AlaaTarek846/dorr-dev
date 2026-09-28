<?php

namespace Modules\Sms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Sms\Http\Requests\SmsAccountRequest;
use Modules\Sms\Http\Requests\SmsSendTestRequest;
use Modules\Sms\Http\Resources\SmsAccountResource;
use Modules\Sms\Services\Sms\SmsAccountService;

/**
 * Account endpoints are thin: they validate input, hand a raw id to the
 * service, and wrap the result in the API envelope. Module routes do not run
 * Laravel's SubstituteBindings, so an id is passed and resolved in the service
 * rather than relying on implicit model binding.
 */
class SmsAccountController extends Controller implements HasMiddleware
{
    public function __construct(protected SmsAccountService $service) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('sms-accounts', [
            ['view', ['index', 'show', 'dropdown', 'providersDropdown']],
            ['create', ['store']],
            ['update', ['update', 'setDefault']],
            ['delete', ['destroy']],
            ['change-status', ['toggleActive']],
            ['multiple-delete', ['deleteMultiple']],
            ['test', ['test', 'testDraft', 'sendTest', 'balance']],
        ]);
    }

    public function index(Request $request)
    {
        return ApiResponse::paginated(
            $this->service->query($request),
            SmsAccountResource::class,
        );
    }

    public function store(SmsAccountRequest $request)
    {
        return ApiResponse::created(
            new SmsAccountResource($this->service->create($request->validated())),
            __('sms.accounts.created'),
        );
    }

    public function show(int|string $sms_account)
    {
        return ApiResponse::success(
            new SmsAccountResource($this->service->findOrFail($sms_account)),
            __('sms.accounts.fetched'),
        );
    }

    public function update(SmsAccountRequest $request, int|string $sms_account)
    {
        return ApiResponse::success(
            new SmsAccountResource($this->service->update($sms_account, $request->validated())),
            __('sms.accounts.updated'),
        );
    }

    public function destroy(int|string $sms_account)
    {
        $this->service->delete($sms_account);

        return ApiResponse::success([], __('sms.accounts.deleted'));
    }

    public function deleteMultiple(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:sms_accounts,id'],
        ]);

        $this->service->deleteMultiple($validated['ids']);

        return ApiResponse::success([], __('sms.accounts.deleted'));
    }

    public function setDefault(int|string $sms_account)
    {
        $this->service->setDefault($sms_account);

        return ApiResponse::success([], __('sms.accounts.default_set'));
    }

    public function toggleActive(int|string $sms_account)
    {
        $isActive = $this->service->toggleActive($sms_account);

        return ApiResponse::success(
            ['is_active' => $isActive],
            $isActive ? __('sms.accounts.activated') : __('sms.accounts.deactivated'),
        );
    }

    public function dropdown()
    {
        return ApiResponse::success($this->service->dropdown());
    }

    public function providersDropdown()
    {
        return ApiResponse::success($this->service->providersDropdown());
    }

    public function test(int|string $sms_account)
    {
        return ApiResponse::success(
            $this->service->testConnection($sms_account),
            __('sms.accounts.connection_successful'),
        );
    }

    public function testDraft(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'integer', 'exists:sms_providers,id'],
            'configuration' => ['required', 'array'],
            'account_id' => ['nullable', 'integer', 'exists:sms_accounts,id'],
        ]);

        return ApiResponse::success(
            $this->service->testDraft(
                $validated['provider_id'],
                $validated['configuration'],
                $validated['account_id'] ?? null,
            ),
            __('sms.accounts.connection_successful'),
        );
    }

    public function sendTest(SmsSendTestRequest $request)
    {
        return ApiResponse::success(
            $this->service->sendTest(
                $request->input('account_id'),
                $request->string('to')->toString(),
                $request->integer('country_id'),
                $request->string('message')->toString(),
            ),
            __('sms.accounts.test_sent'),
        );
    }

    public function balance(int|string $sms_account)
    {
        return ApiResponse::success(
            $this->service->balance($sms_account),
            __('sms.accounts.balance_retrieved'),
        );
    }
}
