<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Http\Resources\RegulatorStatusPresenter;
use Modules\Rides\Models\DriverRegulatorStatus;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Regulator\Wasl\WaslDriverService;

/**
 * The driver's "Wasl approval" screen (D-9): where they stand, why not in their language, when
 * they may try again — and the buttons to register (after the documents) or check again.
 */
class DriverRegulatorController extends Controller
{
    public function __construct(
        private readonly WaslDriverService $wasl,
        private readonly DriverEligibility $eligibility,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $regulator = $this->eligibility->regulatorFor($driver);

        return ApiResponse::success($regulator === null
            ? ['regulator' => null, 'required' => false]
            : $this->present($driver, $this->wasl->statusOf($driver)), __('api.retrieved'));
    }

    public function register(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        return ApiResponse::success($this->present($driver, $this->wasl->register($driver)), __('api.updated'));
    }

    public function check(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        return ApiResponse::success($this->present($driver, $this->wasl->check($driver)), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(Driver $driver, DriverRegulatorStatus $status): array
    {
        return RegulatorStatusPresenter::present($driver, $status, $this->eligibility);
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
