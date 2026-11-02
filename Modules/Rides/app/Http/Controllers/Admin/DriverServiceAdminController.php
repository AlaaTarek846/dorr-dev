<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Rides\Models\DriverService;
use Modules\Rides\Models\DriverServiceRule;
use Modules\Rides\Services\Drivers\DriverServices;

/**
 * Admin → Rides → Captain services (O-89): the services captains work in (name, icon, colour, order,
 * coming soon), and for each country which kind of vehicle may work in which service.
 */
class DriverServiceAdminController extends Controller implements HasMiddleware
{
    private const ICONS = ['car', 'timer', 'calendar', 'group', 'flight', 'bus', 'boat', 'person', 'school', 'package', 'restaurant', 'moto', 'star'];

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('driver-services', [
            ['view', ['index', 'rules']],
            ['create', ['store']],
            ['update', ['update', 'saveRules']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'services' => DriverService::query()->orderBy('sort_order')->get()->map(fn (DriverService $s) => $this->present($s))->values(),
            'kinds' => DriverServices::KINDS,
            'icons' => self::ICONS,
            'countries' => Country::query()->where('status', true)->get()->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
        ], __('api.retrieved'));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $service = DriverService::query()->create($this->validated($request));
        $audit->record('driver_service.created', $service, null, $service->toArray());

        return ApiResponse::success($this->present($service->refresh()), __('api.created'), 201);
    }

    public function update(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $service = DriverService::query()->findOrFail($id);
        $before = $service->toArray();
        $service->fill($this->validated($request, $service->id))->save();
        $audit->record('driver_service.updated', $service, $before, $service->toArray());

        return ApiResponse::success($this->present($service->refresh()), __('api.updated'));
    }

    public function destroy(int $id, AuditLogger $audit): JsonResponse
    {
        $service = DriverService::query()->findOrFail($id);
        $audit->record('driver_service.deleted', $service, $service->toArray(), null);
        $service->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * One country's matrix: for each service, the kinds of vehicle that may work in it. A country never
     * set shows (and works with) the defaults until it is saved.
     */
    public function rules(Request $request, DriverServices $services): JsonResponse
    {
        $countryId = (int) $request->validate(['country_id' => ['required', 'integer', Rule::exists('countries', 'id')]])['country_id'];
        $rows = DriverServiceRule::query()->where('country_id', $countryId)->get()->keyBy(fn (DriverServiceRule $r) => $r->driver_service_id.'|'.$r->vehicle_kind);
        $saved = $rows->isNotEmpty();
        $matrix = [];
        foreach (DriverService::query()->orderBy('sort_order')->get() as $service) {
            foreach (DriverServices::KINDS as $kind) {
                $matrix[$service->key][$kind] = $saved
                    ? (bool) $rows->get($service->id.'|'.$kind)?->enabled
                    : in_array($service->key, DriverServices::DEFAULTS[$kind] ?? [], true);
            }
        }

        return ApiResponse::success(['country_id' => $countryId, 'saved' => $saved, 'matrix' => $matrix], __('api.retrieved'));
    }

    /** Saves a whole country's matrix at once: `{country_id, matrix: {service_key: {kind: bool}}}`. */
    public function saveRules(Request $request, AuditLogger $audit, DriverServices $services): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'matrix' => ['required', 'array'],
            'matrix.*' => ['array'],
            'matrix.*.*' => ['boolean'],
        ]);
        $countryId = (int) $data['country_id'];
        $ids = DriverService::query()->pluck('id', 'key');
        DB::transaction(function () use ($data, $countryId, $ids) {
            foreach ($data['matrix'] as $key => $kinds) {
                if (! isset($ids[$key])) {
                    continue;
                }
                foreach ($kinds as $kind => $on) {
                    if (! in_array($kind, DriverServices::KINDS, true)) {
                        continue;
                    }
                    DriverServiceRule::query()->updateOrCreate(
                        ['driver_service_id' => $ids[$key], 'country_id' => $countryId, 'vehicle_kind' => $kind],
                        ['enabled' => (bool) $on],
                    );
                }
            }
        });
        DriverServices::forget();
        $audit->record('driver_service.rules_saved', null, null, ['country_id' => $countryId, 'matrix' => $data['matrix']]);

        return $this->rules(new Request(['country_id' => $countryId]), $services);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z_]{2,32}$/', Rule::unique('driver_services', 'key')->ignore($ignoreId)],
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:60'],
            'name.en' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:160'],
            'description.en' => ['nullable', 'string', 'max:160'],
            'icon' => ['required', Rule::in(self::ICONS)],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'coming_soon' => ['boolean'],
            'status' => ['boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(DriverService $s): array
    {
        return [
            'id' => $s->id, 'key' => $s->key, 'name' => $s->name, 'description' => $s->description, 'icon' => $s->icon,
            'color' => $s->color, 'sort_order' => $s->sort_order, 'coming_soon' => $s->coming_soon, 'status' => $s->status,
        ];
    }
}
