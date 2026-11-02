<?php

namespace Modules\Driver\Http\Controllers\Driver;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Contracts\DriverWorkGate;
use Modules\Driver\Enums\DocumentStatus;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\VehicleBodyType;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Exceptions\DriverException;
use Modules\Driver\Http\Resources\DriverResource;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverDocument;
use Modules\Driver\Models\DriverDocumentType;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Driver\Services\DocumentRequirements;
use Modules\Driver\Services\DriverRecords;

/**
 * Signing up in the driver app (Jawad's steps: details → vehicle → documents → review): what is
 * still missing, the documents required from this driver (their country, citizen or resident),
 * uploading them, adding the vehicle, and sending it all for review. The admin then approves —
 * and in a country with a regulator, Wasl too (docs/rides-plan.md §11.3).
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly DocumentRequirements $requirements,
        private readonly DriverRecords $records,
        private readonly DriverWorkGate $gate,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($this->driver($request)), __('api.retrieved'));
    }

    /** The pickers: vehicle catalog, body types, features, nationalities, cities. */
    public function options(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $cities = class_exists('Modules\\Discover\\Models\\DiscoverCity')
            ? \Modules\Discover\Models\DiscoverCity::query()->with('translations')->where('country_id', $driver->country_id)->where('status', true)->orderBy('sort_order')->get()
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->translatedName()])->values()
            : collect();

        return ApiResponse::success([
            'makes' => VehicleMake::query()->with(['translations', 'models.translations'])->where('status', true)->orderBy('sort_order')->get()
                ->map(fn (VehicleMake $m) => [
                    'id' => $m->id, 'name' => $m->translated('name'),
                    'models' => $m->models->where('status', true)->map(fn (VehicleModel $vm) => ['id' => $vm->id, 'name' => $vm->translated('name'), 'body_type' => $vm->body_type?->value, 'seats' => $vm->seats])->values(),
                ])->values(),
            'colors' => VehicleColor::query()->with('translations')->where('status', true)->get()
                ->map(fn (VehicleColor $c) => ['id' => $c->id, 'name' => $c->translated('name'), 'hex' => $c->hex])->values(),
            'body_types' => array_column(VehicleBodyType::cases(), 'value'),
            'vehicle_features' => DriverRecords::VEHICLE_FEATURES,
            'genders' => array_column(Gender::cases(), 'value'),
            'id_types' => ['national_id', 'iqama', 'passport'],
            'nationalities' => Country::query()->with('translations')->where('status', true)->get()
                ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'cities' => $cities,
        ], __('api.retrieved'));
    }

    /** The driver's own details. Identity fields lock once the account is active (the admin changes them then). */
    public function updateProfile(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $locked = $driver->status === DriverStatus::Active;
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:191'],
            'city_id' => ['nullable', 'integer'],
            ...($locked ? [] : [
                'gender' => ['nullable', Rule::enum(Gender::class)],
                'date_of_birth' => ['nullable', 'date', 'before:-18 years'],
                'national_id' => ['nullable', 'string', 'max:20', Rule::unique('drivers', 'national_id')->where('id_type', $request->input('id_type', $driver->id_type))->ignore($driver->id)],
                'id_type' => ['nullable', Rule::in(['national_id', 'iqama', 'passport'])],
                'nationality_country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            ]),
        ]);
        $driver->fill($data)->save();

        return ApiResponse::success($this->present($driver->refresh()), __('api.updated'));
    }

    public function avatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:5120']]);
        $driver = $this->driver($request);
        $driver->setSingleMedia('avatar', $request->file('avatar'));

        return ApiResponse::success($this->present($driver->refresh()), __('api.updated'));
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $this->records->saveVehicle($driver, $request->validate(DriverRecords::vehicleRules($driver)));

        return ApiResponse::success($this->present($driver), __('api.created'), 201);
    }

    /** Only a vehicle not approved yet; editing a rejected one sends it back for review. */
    public function updateVehicle(Request $request, int $vehicle): JsonResponse
    {
        $driver = $this->driver($request);
        $model = Vehicle::query()->where('driver_id', $driver->id)->findOrFail($vehicle);
        if ($model->status === VehicleStatus::Approved) {
            throw new DriverException('vehicle_locked', 409);
        }
        $model = $this->records->saveVehicle($driver, $request->validate(DriverRecords::vehicleRules($driver, $model)), $model);
        $model->forceFill(['status' => VehicleStatus::Pending])->save();

        return ApiResponse::success($this->present($driver), __('api.updated'));
    }

    /** Multipart: `document_type_id`, `front` (+ `back`, `number`, `expires_at`, `vehicle_id` as the type needs). */
    public function storeDocument(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $type = DriverDocumentType::query()->find($request->integer('document_type_id'));
        if ($type?->applies_to?->value === 'vehicle' && ! $request->filled('vehicle_id')) {
            // The vehicle being signed up with, when the app doesn't say.
            $request->merge(['vehicle_id' => $driver->vehicles()->latest('id')->value('id')]);
        }
        $data = $request->validate(DriverRecords::documentRules($driver, $type));
        $this->records->addDocument($driver, $data);

        return ApiResponse::success($this->present($driver), __('api.created'), 201);
    }

    /** O-64: the captain has no vehicle (or has one after all). */
    public function noVehicle(Request $request): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $driver = $this->driver($request);
        $driver->forceFill(['no_vehicle' => (bool) $data['on']])->save();

        return ApiResponse::success($this->present($driver->refresh()), __('api.updated'));
    }

    /** Everything is in: the account goes to review. */
    public function submit(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $state = $this->present($driver);
        if ($state['missing'] !== []) {
            throw new DriverException('onboarding_incomplete', 422, [], ['missing' => $state['missing']]);
        }
        if ($driver->status === DriverStatus::PendingDocuments) {
            $driver->forceFill(['status' => DriverStatus::UnderReview])->save();
        }

        return ApiResponse::success($this->present($driver->refresh()), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(Driver $driver): array
    {
        $driver->load(['vehicles.make.translations', 'vehicles.model.translations', 'vehicles.color.translations']);
        $vehicle = $driver->vehicles->firstWhere('is_active', true) ?? $driver->vehicles->sortByDesc('id')->first();
        $documents = DriverDocument::query()->where('driver_id', $driver->id)
            ->whereNotIn('status', [DocumentStatus::Replaced->value])->latest('id')->get();

        // O-64: without a vehicle, its documents aren't asked for.
        $withoutVehicle = $vehicle === null && (bool) $driver->no_vehicle;
        $required = $this->requirements->for($driver, config('rides.service_module_name', 'passenger_ride'))
            ->reject(fn (DriverDocumentType $type) => $withoutVehicle && $type->applies_to->value === 'vehicle')
            ->map(function (DriverDocumentType $type) use ($documents, $vehicle) {
            $mine = $documents->first(fn (DriverDocument $d) => $d->document_type_id === $type->id
                && ($type->applies_to->value !== 'vehicle' || $d->vehicle_id === $vehicle?->id));

            return [
                'type' => [
                    'id' => $type->id, 'code' => $type->code, 'name' => $type->translated('name'), 'instructions' => $type->translated('instructions'),
                    'applies_to' => $type->applies_to->value, 'has_number' => $type->has_number, 'has_expiry' => $type->has_expiry, 'needs_back_side' => $type->needs_back_side,
                ],
                'document' => $mine === null ? null : [
                    'id' => $mine->id, 'status' => $mine->status->value, 'rejection_reason' => $mine->rejection_reason,
                    'number' => $mine->number, 'expires_at' => $mine->expires_at?->toDateString(),
                    'front_url' => $mine->getFirstMediaUrl('front') ?: null, 'back_url' => $mine->getFirstMediaUrl('back') ?: null,
                ],
            ];
        })->values();

        $profileMissing = array_keys(array_filter([
            'name' => blank($driver->name),
            'national_id' => blank($driver->national_id),
            'date_of_birth' => $driver->date_of_birth === null,
            // O-74: For Her goes to women captains only — so it must be known.
            'gender' => $driver->gender === null,
        ]));
        $missing = [
            ...$profileMissing,
            ...($vehicle === null && ! $driver->no_vehicle ? ['vehicle'] : []),
            ...$required->filter(fn (array $r) => $r['document'] === null || $r['document']['status'] === DocumentStatus::Rejected->value)
                ->map(fn (array $r) => 'document:'.$r['type']['code'])->all(),
        ];

        return [
            'driver' => (new DriverResource($driver))->resolve(),
            'profile' => [
                'name' => $driver->name, 'email' => $driver->email, 'gender' => $driver->gender?->value, 'date_of_birth' => $driver->date_of_birth?->toDateString(),
                'national_id' => $driver->national_id, 'id_type' => $driver->id_type, 'nationality_country_id' => $driver->nationality_country_id, 'city_id' => $driver->city_id,
                'locked' => $driver->status === DriverStatus::Active,
            ],
            'steps' => [
                'profile' => $profileMissing === [],
                'vehicle' => $vehicle !== null || (bool) $driver->no_vehicle,
                'documents' => $required->every(fn (array $r) => $r['document'] !== null && $r['document']['status'] !== DocumentStatus::Rejected->value),
            ],
            'vehicle' => $vehicle === null ? null : [
                'id' => $vehicle->id, 'make_id' => $vehicle->make_id, 'model_id' => $vehicle->model_id, 'color_id' => $vehicle->color_id,
                'make' => $vehicle->make?->translated('name'), 'model' => $vehicle->model?->translated('name'), 'color' => $vehicle->color?->translated('name'), 'color_hex' => $vehicle->color?->hex,
                'year' => $vehicle->year, 'body_type' => $vehicle->body_type?->value, 'seats' => $vehicle->seats, 'plate_letters' => $vehicle->plate_letters,
                'plate_number' => $vehicle->plate_number, 'plate_type' => $vehicle->plate_type, 'sequence_number' => $vehicle->sequence_number,
                'features' => $vehicle->features ?? [], 'status' => $vehicle->status->value, 'is_active' => (bool) $vehicle->is_active,
            ],
            'no_vehicle' => (bool) $driver->no_vehicle,
            'required_documents' => $required,
            'missing' => $missing,
            'can_submit' => $missing === [] && $driver->status === DriverStatus::PendingDocuments,
            // What still keeps them from working once everything is in (admin review, Wasl…).
            'work_problem' => $this->gate->reasonNotToWork($driver),
        ];
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
