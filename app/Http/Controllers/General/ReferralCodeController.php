<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Http\Requests\General\ReferralCodeRequest;
use App\Services\General\ReferralCodeService;
use App\Support\Admin\AdminPermissionMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Dashboard: list, show, and deactivate referral codes (`referral-codes.view` / `change-status`).
 */
class ReferralCodeController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('referral-codes', [
            ['view', ['index', 'show']],
            ['change-status', ['changeStatus']],
        ]);
    }

    public function __construct(protected ReferralCodeService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int|string $referral_code)
    {
        return $this->service->find($referral_code);
    }

    public function changeStatus(ReferralCodeRequest $request, int|string $referral_code)
    {
        return $this->service->changeStatus($referral_code, (bool) $request->validated('status'));
    }
}
