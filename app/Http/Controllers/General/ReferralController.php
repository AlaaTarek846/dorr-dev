<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Services\General\ReferralService;
use App\Support\Admin\AdminPermissionMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Dashboard: list and show referrals (`referrals.view`).
 */
class ReferralController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('referrals', [
            ['view', ['index', 'show']],
        ]);
    }

    public function __construct(protected ReferralService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int|string $referral)
    {
        return $this->service->find($referral);
    }
}
