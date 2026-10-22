<?php

namespace Modules\Rides\Http\Controllers\Hire;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverHire;
use Modules\Rides\Services\Hire\DriverHireService;

/** The captain's side of «find a driver» (O-26c): open requests near me, accept, my jobs, arrived → started → ended. */
class DriverHireController extends Controller
{
    public function __construct(private readonly DriverHireService $hires) {}

    public function open(Request $request): JsonResponse
    {
        return ApiResponse::success($this->hires->openNear($this->driver($request))->map(fn (DriverHire $h) => HirePresenter::hire($h, false))->values(), __('api.retrieved'));
    }

    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::success($this->hires->mineAsDriver($this->driver($request))->map(fn (DriverHire $h) => HirePresenter::hire($h, false))->values(), __('api.retrieved'));
    }

    public function accept(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(HirePresenter::hire($this->hires->accept($this->driver($request), $this->find($uuid)), false), __('rides.messages.hire_accepted'));
    }

    /** `{to: arrived | started | completed}` */
    public function move(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['to' => ['required', Rule::in(['arrived', 'started', 'completed'])]]);

        return ApiResponse::success(HirePresenter::hire($this->hires->move($this->driver($request), $this->find($uuid), $data['to']), false), __('api.updated'));
    }

    public function release(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(HirePresenter::hire($this->hires->release($this->driver($request), $this->find($uuid)), false), __('api.updated'));
    }

    private function find(string $uuid): DriverHire
    {
        return DriverHire::query()->where('uuid', $uuid)->first() ?? throw new RidesException('hire_not_found', 404);
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
