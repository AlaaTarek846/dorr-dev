<?php

namespace Modules\Driver\Http\Controllers\Admin;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\Admin;
use Modules\Driver\Enums\DriverResidency;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\VehicleBodyType;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Http\Resources\Admin\AdminDriverResource;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverDocument;
use Modules\Driver\Models\DriverDocumentType;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Driver\Services\Admin\DriverAdminService;
use Modules\Driver\Services\DriverRecords;

/**
 * Admin → Drivers: the list, a driver's page, registering one from the dashboard (citizen or
 * resident, any country), their status, vehicles and documents (docs/rides-plan.md §11.3).
 */
class DriverController extends Controller implements HasMiddleware
{
    public function __construct(private readonly DriverAdminService $drivers) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('drivers', [
            ['view', ['index', 'show', 'options']],
            ['create', ['store']],
            ['update', ['update', 'status', 'storeVehicle', 'updateVehicle', 'vehicleStatus', 'activateVehicle', 'storeDocument', 'reviewDocument', 'destroyDocument']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(DriverStatus::class)],
            'residency' => ['nullable', Rule::enum(DriverResidency::class)],
            'country_id' => ['nullable', 'integer'],
            'registered_by' => ['nullable', Rule::in(['app', 'admin'])],
            'pending_documents' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);
        $page = $this->drivers->list($filters, (int) ($filters['per_page'] ?? 15));

        return ApiResponse::fromPaginator($page, AdminDriverResource::collection($page->getCollection())->resolve($request), __('api.retrieved'));
    }

    public function show(Request $request, Driver $driver): JsonResponse
    {
        return $this->present($driver, $request);
    }

    public function store(Request $request): JsonResponse
    {
        $driver = $this->drivers->create($this->validated($request, true), $this->admin($request));

        return $this->present($driver, $request, 201);
    }

    public function update(Request $request, Driver $driver): JsonResponse
    {
        $driver = $this->drivers->update($driver, $this->validated($request, false), $this->admin($request));

        return $this->present($driver, $request);
    }

    public function status(Request $request, Driver $driver): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(DriverStatus::class)],
            'reason' => ['nullable', 'required_unless:status,active', 'string', 'max:255'],
        ]);
        $driver = $this->drivers->setStatus($driver, DriverStatus::from($data['status']), $data['reason'] ?? null, $this->admin($request));

        return $this->present($driver, $request);
    }

    public function storeVehicle(Request $request, Driver $driver): JsonResponse
    {
        $this->drivers->saveVehicle($driver, $this->vehicleData($request, $driver), $this->admin($request));

        return $this->present($driver, $request, 201);
    }

    public function updateVehicle(Request $request, Driver $driver, Vehicle $vehicle): JsonResponse
    {
        $this->owned($driver, $vehicle->driver_id);
        $this->drivers->saveVehicle($driver, $this->vehicleData($request, $driver, $vehicle), $this->admin($request), $vehicle);

        return $this->present($driver, $request);
    }

    public function vehicleStatus(Request $request, Driver $driver, Vehicle $vehicle): JsonResponse
    {
        $this->owned($driver, $vehicle->driver_id);
        $data = $request->validate(['status' => ['required', Rule::enum(VehicleStatus::class)]]);
        $this->drivers->setVehicleStatus($vehicle, VehicleStatus::from($data['status']), $this->admin($request));

        return $this->present($driver, $request);
    }

    public function activateVehicle(Request $request, Driver $driver, Vehicle $vehicle): JsonResponse
    {
        $this->owned($driver, $vehicle->driver_id);
        $this->drivers->activateVehicle($vehicle, $this->admin($request));

        return $this->present($driver, $request);
    }

    /** Multipart: `front`, optional `back`, the type, the number and the expiry. Approved at once unless `approve=0`. */
    public function storeDocument(Request $request, Driver $driver): JsonResponse
    {
        $type = DriverDocumentType::query()->find($request->integer('document_type_id'));
        // The admin may also record an expired document (to keep the history), and approve later.
        $rules = DriverRecords::documentRules($driver, $type);
        $rules['expires_at'] = array_values(array_diff($rules['expires_at'], ['after:today']));
        $rules['document_type_id'] = ['required', 'integer', Rule::exists('driver_document_types', 'id')->whereNull('deleted_at')];
        $data = $request->validate($rules + ['approve' => ['nullable', 'boolean']]);
        $data['approve'] = $request->has('approve') ? $request->boolean('approve') : true;
        $this->drivers->addDocument($driver, $data, $this->admin($request));

        return $this->present($driver, $request, 201);
    }

    public function reviewDocument(Request $request, Driver $driver, DriverDocument $document): JsonResponse
    {
        $this->owned($driver, $document->driver_id);
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['nullable', 'required_if:approve,false', 'string', 'max:255'],
        ]);
        $this->drivers->review($document, (bool) $data['approve'], $data['reason'] ?? null, $this->admin($request));

        return $this->present($driver, $request);
    }

    public function destroyDocument(Request $request, Driver $driver, DriverDocument $document): JsonResponse
    {
        $this->owned($driver, $document->driver_id);
        $document->delete();

        return $this->present($driver, $request);
    }

    /** Everything the driver forms pick from. */
    public function options(): JsonResponse
    {
        $cities = class_exists('Modules\\Discover\\Models\\DiscoverCity')
            ? \Modules\Discover\Models\DiscoverCity::query()->with('translations')->where('status', true)->orderBy('sort_order')->get()
                ->map(fn ($c) => ['id' => (int) $c->id, 'country_id' => (int) $c->country_id, 'name' => $c->translatedName()])->values()
            : collect();

        return ApiResponse::success([
            'countries' => Country::query()->with('translations')->where('status', true)->get()
                ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code, 'dial_code' => $c->dial_code, 'phone_length' => $c->phone_length])->values(),
            'cities' => $cities,
            'statuses' => DriverStatus::values(),
            'residencies' => DriverResidency::values(),
            'id_types' => ['national_id', 'iqama', 'passport'],
            'genders' => array_column(Gender::cases(), 'value'),
            'vehicle_statuses' => VehicleStatus::values(),
            'body_types' => array_column(VehicleBodyType::cases(), 'value'),
            'vehicle_features' => DriverRecords::VEHICLE_FEATURES,
            'document_types' => DriverDocumentType::query()->with('translations')->where('status', true)->orderBy('sort_order')->get()
                ->map(fn (DriverDocumentType $t) => [
                    'id' => $t->id, 'code' => $t->code, 'name' => $t->translated('name'), 'applies_to' => $t->applies_to->value,
                    'has_number' => $t->has_number, 'has_expiry' => $t->has_expiry, 'needs_back_side' => $t->needs_back_side,
                ])->values(),
            'makes' => VehicleMake::query()->with(['translations', 'models.translations'])->where('status', true)->orderBy('sort_order')->get()
                ->map(fn (VehicleMake $m) => [
                    'id' => $m->id, 'name' => $m->translated('name'),
                    'models' => $m->models->where('status', true)->map(fn (VehicleModel $vm) => ['id' => $vm->id, 'name' => $vm->translated('name'), 'body_type' => $vm->body_type?->value, 'seats' => $vm->seats])->values(),
                ])->values(),
            'colors' => VehicleColor::query()->with('translations')->where('status', true)->get()
                ->map(fn (VehicleColor $c) => ['id' => $c->id, 'name' => $c->translated('name'), 'hex' => $c->hex])->values(),
        ], __('api.retrieved'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'phone' => [$required, 'string', 'max:20'],
            'country_id' => [$required, 'integer', Rule::exists('countries', 'id')],
            'city_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:191'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date', 'before:-18 years'],
            'national_id' => ['nullable', 'string', 'max:20'],
            'id_type' => ['nullable', Rule::in(['national_id', 'iqama', 'passport'])],
            'residency' => [$required, Rule::enum(DriverResidency::class)],
            'nationality_country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);
    }

    /** @return array<string, mixed> */
    private function vehicleData(Request $request, Driver $driver, ?Vehicle $vehicle = null): array
    {
        return $request->validate(DriverRecords::vehicleRules($driver, $vehicle));
    }

    private function present(Driver $driver, Request $request, int $status = 200): JsonResponse
    {
        $driver->load(['country.translations', 'activeVehicle', 'presence']);

        return ApiResponse::success((new AdminDriverResource($driver))->detailed()->resolve($request), __($status === 201 ? 'api.created' : 'api.retrieved'), $status);
    }

    private function owned(Driver $driver, ?int $ownerId): void
    {
        abort_unless((int) $ownerId === (int) $driver->id, 404);
    }

    private function admin(Request $request): Admin
    {
        /** @var Admin */
        return $request->user();
    }
}
