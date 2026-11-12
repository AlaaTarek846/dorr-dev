<?php

namespace Modules\Provider\Http\Controllers\Admin;

use App\Enums\ServiceAudience;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\ServiceCategory;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Provider\Exceptions\ProviderException;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Models\VehicleType;

/** Admin → Providers → Vehicle types (A-11): name, image, order, the countries where it exists and the services it fits. */
class ProviderVehicleTypeAdminController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('provider-vehicle-types', [
            ['view', ['index', 'options']],
            ['create', ['store']],
            ['update', ['update']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(): JsonResponse
    {
        $types = VehicleType::query()->with(['translations', 'countries:id', 'services:id'])->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($types->map(fn (VehicleType $t) => $this->present($t))->values(), __('api.retrieved'));
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => Country::query()->with('translations')->where('status', true)->get()
                ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'services' => ServiceCategory::query()->with('translations')->whereJsonContains('audiences', ServiceAudience::Provider->value)->orderBy('sort_order')->get()
                ->map(fn (ServiceCategory $s) => ['id' => $s->id, 'module_name' => $s->module_name, 'name' => $s->translatedName() ?? $s->module_name])->values(),
        ], __('api.retrieved'));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);
        $type = DB::transaction(fn () => $this->save(new VehicleType, $data, $request));
        $audit->record('provider_vehicle_type.created', $type, null, collect($data)->except('image')->all());

        return ApiResponse::success($this->present($type->fresh(['translations', 'countries:id', 'services:id'])), __('api.created'), 201);
    }

    public function update(Request $request, VehicleType $vehicleType, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request, $vehicleType);
        $before = $this->present($vehicleType->load(['translations', 'countries:id', 'services:id']));
        DB::transaction(fn () => $this->save($vehicleType, $data, $request));
        $after = $this->present($vehicleType->fresh(['translations', 'countries:id', 'services:id']));
        $audit->record('provider_vehicle_type.updated', $vehicleType, $before, $after);

        return ApiResponse::success($after, __('api.updated'));
    }

    public function destroy(VehicleType $vehicleType, AuditLogger $audit): JsonResponse
    {
        if (Vehicle::query()->withTrashed()->where('vehicle_type_id', $vehicleType->id)->exists()) {
            throw new ProviderException('vehicle_type_in_use', 409);
        }
        $vehicleType->delete();
        $audit->record('provider_vehicle_type.deleted', $vehicleType);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @param array<string, mixed> $data */
    private function save(VehicleType $type, array $data, Request $request): VehicleType
    {
        $type->fill(collect($data)->only(['code', 'status', 'sort_order'])->all());
        $type->fillAllTranslations(['name' => $data['name'], 'description' => $data['description'] ?? []])->save();
        $type->countries()->sync($data['country_ids'] ?? []);
        $type->services()->sync($data['service_ids'] ?? []);
        if ($request->hasFile('image')) {
            $type->setSingleMedia('image', $request->file('image'));
        }

        return $type;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?VehicleType $type = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/', Rule::unique('provider_vehicle_types', 'code')->ignore($type?->id)],
            'status' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:120'],
            'name.en' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:500'],
            'description.en' => ['nullable', 'string', 'max:500'],
            'country_ids' => ['array'],
            'country_ids.*' => ['integer', Rule::exists('countries', 'id')],
            'service_ids' => ['array'],
            'service_ids.*' => ['integer', Rule::exists('service_categories', 'id')],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(VehicleType $type): array
    {
        $translation = fn (string $field) => $type->translations->mapWithKeys(fn ($t) => [$t->locale => $t->{$field}])->all();

        return [
            'id' => $type->id,
            'code' => $type->code,
            'name' => $type->translated('name'),
            'names' => $translation('name'),
            'descriptions' => $translation('description'),
            'image' => $type->getSingleMediaUrl('image') ?: null,
            'status' => (bool) $type->status,
            'sort_order' => $type->sort_order,
            'country_ids' => $type->countries->pluck('id')->values(),
            'service_ids' => $type->services->pluck('id')->values(),
        ];
    }
}
