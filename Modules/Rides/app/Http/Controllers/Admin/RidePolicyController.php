<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Rides\Http\Controllers\Admin\Concerns\RideAdminOptions;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RidePolicy;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Rides\Services\Policy\RidePolicyData;

/**
 * Admin → Rides → Prices & rules: one scope at a time — a country, or a city in it, or one zone (an
 * airport…), for every category or one — showing each key's value inherited from the broader scopes and this scope's
 * own overrides. Saving keeps only the keys this scope changes (validated and audited by
 * RidePolicies::save). Rides already confirmed keep the numbers they were confirmed with.
 */
class RidePolicyController extends Controller implements HasMiddleware
{
    use RideAdminOptions;

    public function __construct(private readonly RidePolicies $policies) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ride-policies', [
            ['view', ['options', 'show', 'index']],
            ['update', ['update']],
        ]);
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => $this->countries(),
            'cities' => $this->cities(),
            'categories' => RideCategory::query()->with('translations')->orderBy('sort_order')->get()
                ->map(fn (RideCategory $c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->translated('name'), 'status' => (bool) $c->status])->values(),
            'zones' => RideZone::query()->with('translations')->orderBy('id')->get()
                ->map(fn (RideZone $z) => ['id' => $z->id, 'country_id' => $z->country_id, 'type' => $z->type->value, 'name' => $z->translated('name')])->values(),
            'defaults' => RidePolicyData::defaults(),
        ], __('api.retrieved'));
    }

    /** Every scope that changes something, to jump to. */
    public function index(): JsonResponse
    {
        $rows = RidePolicy::query()->orderBy('country_id')->orderBy('city_id')->orderBy('zone_id')->orderBy('category_id')->get()
            ->map(fn (RidePolicy $p) => ['id' => $p->id, 'country_id' => $p->country_id, 'city_id' => $p->city_id, 'zone_id' => $p->zone_id, 'category_id' => $p->category_id, 'keys' => array_keys((array) $p->data), 'updated_at' => $p->updated_at?->toIso8601String()])
            ->values();

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(Request $request): JsonResponse
    {
        [$country, $city, $zone, $category] = $this->scope($request);
        $row = $this->row($country, $city, $zone, $category);

        return ApiResponse::success([
            'own' => (object) ((array) $row?->data),
            'inherited' => $this->policies->for($country, $city, $zone, $category, $row?->id)->all(),
            'effective' => $this->policies->for($country, $city, $zone, $category)->all(),
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ], __('api.retrieved'));
    }

    /** `{country_id, city_id?, zone_id?, category_id?, data: {key: value}}` — the scope's whole set of overrides. */
    public function update(Request $request): JsonResponse
    {
        [$country, $city, $zone, $category] = $this->scope($request);
        $data = $request->validate(['data' => ['present', 'array']])['data'];
        // Numbers come from a form: whole-number strings become integers before the type check.
        $defaults = RidePolicyData::defaults();
        foreach ($data as $key => $value) {
            if (is_int($defaults[$key] ?? null) && is_string($value) && preg_match('/^-?\d+$/', $value)) {
                $data[$key] = (int) $value;
            }
        }
        $this->policies->save($country, $city, $zone, $category, $data);

        return $this->show($request);
    }

    /**
     * A zone is its own place: the city is the zone's, whatever the form sent.
     *
     * @return array{0: int, 1: ?int, 2: ?int, 3: ?int}
     */
    private function scope(Request $request): array
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'city_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer', Rule::exists('ride_zones', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('ride_categories', 'id')],
        ]);
        $zone = isset($data['zone_id']) ? (int) $data['zone_id'] : null;
        $city = $zone !== null ? RideZone::query()->whereKey($zone)->value('city_id') : ($data['city_id'] ?? null);

        return [(int) $data['country_id'], $city === null ? null : (int) $city, $zone, isset($data['category_id']) ? (int) $data['category_id'] : null];
    }

    private function row(int $country, ?int $city, ?int $zone, ?int $category): ?RidePolicy
    {
        return RidePolicy::query()->where('country_id', $country)
            ->where(fn ($q) => $zone === null ? $q->whereNull('zone_id') : $q->where('zone_id', $zone))
            ->where(fn ($q) => $city === null ? $q->whereNull('city_id') : $q->where('city_id', $city))
            ->where(fn ($q) => $category === null ? $q->whereNull('category_id') : $q->where('category_id', $category))
            ->first();
    }
}
