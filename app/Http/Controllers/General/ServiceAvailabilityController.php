<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Region;
use App\Models\ServiceAvailability;
use App\Models\ServiceCategory;
use App\Services\General\ServiceAvailabilityService;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Dashboard → Service control: for a country, or one of its regions, switch each service on or off — as a whole, for the
 * customer app, and for the provider app — each on its own.
 */
class ServiceAvailabilityController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ServiceAvailabilityService $availability) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('service-availability', [
            ['view', ['index']],
            ['update', ['update', 'reset']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        [$country, $region] = $this->place($request);

        return ApiResponse::success($this->availability->listFor($country, $region)->values(), __('api.retrieved'));
    }

    public function update(Request $request): JsonResponse
    {
        [$country, $region] = $this->place($request);

        $data = $request->validate([
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'service_enabled' => ['sometimes', 'nullable', 'boolean'],
            'customer_enabled' => ['sometimes', 'nullable', 'boolean'],
            'customer_mode' => ['sometimes', 'nullable', Rule::in(ServiceAvailability::CUSTOMER_MODES)],
            'provider_enabled' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $item = $this->availability->update($country, $region, ServiceCategory::query()->findOrFail($data['service_category_id']), $data);

        return ApiResponse::success($item, __('api.updated'));
    }

    /** A region goes back to following its country for one service. */
    public function reset(Request $request): JsonResponse
    {
        [$country, $region] = $this->place($request);

        $data = $request->validate(['service_category_id' => ['required', 'integer', 'exists:service_categories,id']]);

        abort_if($region === null, 422, __('api.service_switch_required'));

        return ApiResponse::success(
            $this->availability->reset($country, $region, ServiceCategory::query()->findOrFail($data['service_category_id'])),
            __('api.updated'),
        );
    }

    /** @return array{0: Country, 1: Region|null} */
    private function place(Request $request): array
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
        ]);

        $country = Country::query()->findOrFail($data['country_id']);
        $region = isset($data['region_id']) ? Region::query()->where('country_id', $country->id)->find($data['region_id']) : null;

        abort_if(isset($data['region_id']) && $region === null, 422, __('api.region_not_in_country'));

        return [$country, $region];
    }
}
