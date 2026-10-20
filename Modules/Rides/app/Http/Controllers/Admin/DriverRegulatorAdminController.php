<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Driver\Models\Driver;
use Modules\Rides\Http\Resources\RegulatorStatusPresenter;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Regulator\Wasl\WaslDriverService;

/**
 * The Wasl box on a driver's page in the dashboard: where they stand, and the admin's "register at
 * Wasl" / "check again" buttons (Jawad's driver screen). A driver registered from the dashboard
 * goes through Wasl exactly like one from the app (docs/rides-plan.md §11.3).
 */
class DriverRegulatorAdminController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly WaslDriverService $wasl,
        private readonly DriverEligibility $eligibility,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('drivers', [
            ['view', ['show']],
            ['update', ['register', 'check']],
        ]);
    }

    public function show(Driver $driver): JsonResponse
    {
        return ApiResponse::success($this->present($driver), __('api.retrieved'));
    }

    public function register(Driver $driver, AuditLogger $audit): JsonResponse
    {
        $this->wasl->register($driver);
        $audit->record('driver.regulator_register', $driver);

        return ApiResponse::success($this->present($driver), __('api.updated'));
    }

    public function check(Driver $driver, AuditLogger $audit): JsonResponse
    {
        $this->wasl->check($driver);
        $audit->record('driver.regulator_check', $driver);

        return ApiResponse::success($this->present($driver), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(Driver $driver): array
    {
        if ($this->eligibility->regulatorFor($driver) === null) {
            return ['regulator' => null, 'required' => false, 'work_problem' => $this->eligibility->reasonNotToWork($driver)];
        }

        return RegulatorStatusPresenter::present($driver, $this->wasl->statusOf($driver), $this->eligibility);
    }
}
