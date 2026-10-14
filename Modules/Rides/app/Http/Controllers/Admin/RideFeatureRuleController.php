<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Rides\Http\Controllers\Admin\Concerns\RideAdminOptions;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Services\Features\RideFeatures;

/**
 * Admin → Rides → Switches (owner: every service and feature can be turned on or off): the service
 * itself, each category and each feature, for a country or one of its cities. No rule = off; a
 * city's rule beats its country's (docs/rides-plan.md §5).
 */
class RideFeatureRuleController extends Controller implements HasMiddleware
{
    use RideAdminOptions;

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ride-feature-rules', [
            ['view', ['index', 'options']],
            ['create', ['store']],
            ['update', ['update']],
            ['delete', ['destroy']],
        ]);
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'features' => $this->features(),
            'countries' => $this->countries(),
            'cities' => $this->cities(),
        ], __('api.retrieved'));
    }

    public function index(): JsonResponse
    {
        $cities = $this->cities()->keyBy('id');
        $rows = RideFeatureRule::query()->with('country.translations')->orderBy('feature')->orderBy('country_id')->orderBy('city_id')->get()
            ->map(fn (RideFeatureRule $r) => $this->present($r, $cities))->values();

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'feature' => ['required', 'string', Rule::in($this->features())],
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'city_id' => ['nullable', 'integer'],
            'enabled' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $rule = RideFeatureRule::query()->updateOrCreate(
            ['feature' => $data['feature'], 'country_id' => $data['country_id'], 'city_id' => $data['city_id'] ?? null],
            ['enabled' => (bool) $data['enabled'], 'note' => $data['note'] ?? null],
        );
        $audit->record('ride_feature_rule.saved', $rule, null, $data);

        return ApiResponse::success($this->present($rule->load('country.translations'), $this->cities()->keyBy('id')), __('api.created'), 201);
    }

    public function update(Request $request, RideFeatureRule $rideFeatureRule, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $before = $rideFeatureRule->only(['enabled', 'note']);
        $rideFeatureRule->update(['enabled' => (bool) $data['enabled'], 'note' => $data['note'] ?? $rideFeatureRule->note]);
        $audit->record('ride_feature_rule.saved', $rideFeatureRule, $before, $data);

        return ApiResponse::success($this->present($rideFeatureRule->load('country.translations'), $this->cities()->keyBy('id')), __('api.updated'));
    }

    public function destroy(RideFeatureRule $rideFeatureRule, AuditLogger $audit): JsonResponse
    {
        $rideFeatureRule->delete();
        $audit->record('ride_feature_rule.deleted', $rideFeatureRule, $rideFeatureRule->only(['feature', 'country_id', 'city_id', 'enabled']));

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @return list<string> the service, each category, each feature */
    private function features(): array
    {
        return [
            RideFeatures::SERVICE,
            ...RideCategory::query()->orderBy('sort_order')->pluck('slug')->map(fn (string $s) => RideFeatures::categoryFeature($s))->all(),
            ...RideFeatures::FEATURES,
        ];
    }

    /** @return array<string, mixed> */
    private function present(RideFeatureRule $rule, $cities): array
    {
        return [
            'id' => $rule->id,
            'feature' => $rule->feature,
            'country' => ['id' => $rule->country_id, 'code' => $rule->country?->code, 'name' => $rule->country?->translatedName()],
            'city' => $rule->city_id === null ? null : ['id' => $rule->city_id, 'name' => $cities[$rule->city_id]['name'] ?? null],
            'enabled' => (bool) $rule->enabled,
            'note' => $rule->note,
        ];
    }
}
