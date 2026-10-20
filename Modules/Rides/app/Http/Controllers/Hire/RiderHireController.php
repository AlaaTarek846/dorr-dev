<?php

namespace Modules\Rides\Http\Controllers\Hire;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverHire;
use Modules\Rides\Services\Hire\DriverHireService;
use Modules\User\Models\User;

/** The rider's «find a driver» (O-26c): the price, ask, my requests, cancel. */
class RiderHireController extends Controller
{
    public function __construct(private readonly DriverHireService $hires) {}

    /** The price for a place, hours or days — and the limits for the pickers. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'unit' => ['required', Rule::in(['hour', 'day'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:60'],
        ]);
        $q = $this->hires->quote($this->rider($request), (float) $data['lat'], (float) $data['lng'], $data['unit'], (int) $data['quantity']);
        $currency = $q['country']->currency;

        return ApiResponse::success([
            'amount_minor' => $q['amount_minor'],
            'limits' => $q['limits'],
            'currency' => $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) ($currency->decimal_places ?? 2)],
        ], __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup' => ['required', 'array'],
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup.address' => ['required', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'unit' => ['required', Rule::in(['hour', 'day'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:60'],
            'transmission' => ['nullable', Rule::in(['automatic', 'manual'])],
            'car' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $hire = $this->hires->request($this->rider($request), $data, $request->header('Idempotency-Key'));

        return ApiResponse::success(HirePresenter::hire($hire), __('rides.messages.hire_requested'), 201);
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->hires->mineAsRider($this->rider($request))->map(fn (DriverHire $h) => HirePresenter::hire($h))->values(), __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(HirePresenter::hire($this->own($request, $uuid)), __('api.retrieved'));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(HirePresenter::hire($this->hires->cancelByRider($this->rider($request), $this->own($request, $uuid))), __('rides.messages.hire_canceled'));
    }

    private function own(Request $request, string $uuid): DriverHire
    {
        return DriverHire::query()->where('uuid', $uuid)->where('user_id', $this->rider($request)->id)->first()
            ?? throw new RidesException('hire_not_found', 404);
    }

    private function rider(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
