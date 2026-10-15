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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Rides\Enums\PickupPointKind;
use Modules\Rides\Enums\ZoneType;
use Modules\Rides\Http\Controllers\Admin\Concerns\RideAdminOptions;
use Modules\Rides\Models\RidePickupPoint;
use Modules\Rides\Models\RideQueueEntry;
use Modules\Rides\Models\RideZone;

/**
 * Admin → Rides → Zones & pickup points: the polygons with a meaning (airport, pickup-only, blocked…)
 * and, inside each, the approved gates / terminals / entrances / parkings riders choose from (R-16..R-18).
 * Names and instructions in both languages; a zone's own prices and waiting rules are set under
 * Prices & rules (scope: this zone).
 */
class RideZoneController extends Controller implements HasMiddleware
{
    use RideAdminOptions;

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ride-zones', [
            ['view', ['index', 'options']],
            ['create', ['store', 'storePoint']],
            ['update', ['update', 'updatePoint']],
            ['delete', ['destroy', 'destroyPoint']],
        ]);
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => $this->countries(),
            'cities' => $this->cities(),
            'types' => array_map(fn (ZoneType $t) => $t->value, ZoneType::cases()),
            'point_kinds' => array_map(fn (PickupPointKind $k) => $k->value, PickupPointKind::cases()),
        ], __('api.retrieved'));
    }

    public function index(Request $request): JsonResponse
    {
        $zones = RideZone::query()->with(['translations', 'pickupPoints.translations'])
            ->when($request->query('country_id'), fn ($q, $id) => $q->where('country_id', $id))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('priority')->orderBy('id')->get();

        return ApiResponse::success($zones->map(fn (RideZone $z) => $this->present($z))->values(), __('api.retrieved'));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);
        $zone = DB::transaction(function () use ($data) {
            $zone = new RideZone(collect($data)->except('name')->all());
            $zone->fillAllTranslations(['name' => $data['name']])->save();

            return $zone;
        });
        $audit->record('ride_zone.created', $zone, null, $data);

        return ApiResponse::success($this->present($zone->fresh(['translations', 'pickupPoints.translations'])), __('api.created'), 201);
    }

    public function update(Request $request, int $zone, AuditLogger $audit): JsonResponse
    {
        $model = RideZone::query()->findOrFail($zone);
        $data = $this->validated($request);
        $before = $model->only(['type', 'polygon', 'config', 'priority', 'status']);
        DB::transaction(function () use ($model, $data) {
            $model->fill(collect($data)->except('name')->all());
            $model->fillAllTranslations(['name' => $data['name']])->save();
        });
        $audit->record('ride_zone.updated', $model, $before, $data);

        return ApiResponse::success($this->present($model->fresh(['translations', 'pickupPoints.translations'])), __('api.updated'));
    }

    public function destroy(int $zone, AuditLogger $audit): JsonResponse
    {
        $model = RideZone::query()->findOrFail($zone);
        $model->delete();
        $audit->record('ride_zone.deleted', $model);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function storePoint(Request $request, int $zone, AuditLogger $audit): JsonResponse
    {
        $parent = RideZone::query()->findOrFail($zone);
        $data = $this->validatedPoint($request);
        $point = DB::transaction(function () use ($parent, $data) {
            $point = new RidePickupPoint(['zone_id' => $parent->id] + collect($data)->except(['name', 'instructions'])->all());
            $point->fillAllTranslations(['name' => $data['name'], 'instructions' => $data['instructions']])->save();

            return $point;
        });
        $audit->record('ride_pickup_point.created', $point, null, $data);

        return ApiResponse::success($this->presentPoint($point->fresh('translations')), __('api.created'), 201);
    }

    public function updatePoint(Request $request, int $zone, int $point, AuditLogger $audit): JsonResponse
    {
        $model = RidePickupPoint::query()->where('zone_id', $zone)->findOrFail($point);
        $data = $this->validatedPoint($request);
        DB::transaction(function () use ($model, $data) {
            $model->fill(collect($data)->except(['name', 'instructions'])->all());
            $model->fillAllTranslations(['name' => $data['name'], 'instructions' => $data['instructions']])->save();
        });
        $audit->record('ride_pickup_point.updated', $model, null, $data);

        return ApiResponse::success($this->presentPoint($model->fresh('translations')), __('api.updated'));
    }

    public function destroyPoint(int $zone, int $point, AuditLogger $audit): JsonResponse
    {
        $model = RidePickupPoint::query()->where('zone_id', $zone)->findOrFail($point);
        $model->delete();
        $audit->record('ride_pickup_point.deleted', $model);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'city_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::enum(ZoneType::class)],
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:120'],
            'name.en' => ['required', 'string', 'max:120'],
            'polygon' => ['required', 'array', 'min:3', 'max:500'],
            'polygon.*' => ['required', 'array', 'size:2'],
            'polygon.*.0' => ['required', 'numeric', 'between:-90,90'],
            'polygon.*.1' => ['required', 'numeric', 'between:-180,180'],
            'config' => ['nullable', 'array'],
            'config.pickup_required' => ['nullable', 'boolean'],
            'config.dropoff_required' => ['nullable', 'boolean'],
            'config.queue_enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['required', 'boolean'],
        ]);
        $data['polygon'] = array_map(fn (array $p) => [(float) $p[0], (float) $p[1]], $data['polygon']);
        $data['config'] = array_filter(
            [
                'pickup_required' => (bool) ($data['config']['pickup_required'] ?? false),
                'dropoff_required' => (bool) ($data['config']['dropoff_required'] ?? false),
                // D-276: the airport queue (only an airport zone has one; the market's `airport_queue` switch decides too).
                'queue_enabled' => $data['type'] === ZoneType::Airport->value && (bool) ($data['config']['queue_enabled'] ?? false),
            ],
        ) ?: null;
        $data['priority'] ??= 0;

        return $data;
    }

    /** @return array<string, mixed> */
    private function validatedPoint(Request $request): array
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'kind' => ['required', Rule::enum(PickupPointKind::class)],
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:120'],
            'name.en' => ['required', 'string', 'max:120'],
            'instructions' => ['nullable', 'array'],
            'instructions.ar' => ['nullable', 'string', 'max:255'],
            'instructions.en' => ['nullable', 'string', 'max:255'],
            'for_pickup' => ['required', 'boolean'],
            'for_dropoff' => ['required', 'boolean'],
            'status' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        // Both languages always, so clearing a note in the form clears it.
        $data['instructions'] = ['ar' => ($data['instructions']['ar'] ?? '') ?: null, 'en' => ($data['instructions']['en'] ?? '') ?: null];
        $data['sort_order'] ??= 0;

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(RideZone $zone): array
    {
        return [
            'id' => $zone->id,
            'country_id' => $zone->country_id,
            'city_id' => $zone->city_id,
            'type' => $zone->type->value,
            'name' => $zone->translated('name'),
            'names' => $zone->translations->pluck('name', 'locale')->all(),
            'polygon' => $zone->polygon,
            'config' => (object) ((array) $zone->config),
            'priority' => $zone->priority,
            'status' => (bool) $zone->status,
            'queue_size' => RideQueueEntry::query()->where('zone_id', $zone->id)->where('status', 'waiting')->count(),
            'points' => $zone->pickupPoints->sortBy([['sort_order', 'asc'], ['id', 'asc']])->map(fn (RidePickupPoint $p) => $this->presentPoint($p))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentPoint(RidePickupPoint $point): array
    {
        return [
            'id' => $point->id,
            'zone_id' => $point->zone_id,
            'kind' => $point->kind->value,
            'lat' => $point->lat,
            'lng' => $point->lng,
            'name' => $point->translated('name'),
            'names' => $point->translations->pluck('name', 'locale')->all(),
            'instructions' => $point->translations->pluck('instructions', 'locale')->filter()->all(),
            'for_pickup' => $point->for_pickup,
            'for_dropoff' => $point->for_dropoff,
            'status' => $point->status,
            'sort_order' => $point->sort_order,
        ];
    }
}
