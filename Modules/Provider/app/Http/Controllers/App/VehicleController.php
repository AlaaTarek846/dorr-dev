<?php

namespace Modules\Provider\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Provider\Enums\DocumentSubject;
use Modules\Provider\Http\Requests\App\VehicleRequest;
use Modules\Provider\Http\Resources\App\DocumentTypeResource;
use Modules\Provider\Http\Resources\App\ProviderDocumentResource;
use Modules\Provider\Http\Resources\App\VehicleResource;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Services\Documents\DocumentRequirements;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\Provider\Services\Vehicles\VehicleService;

/** The establishment's vehicles with their photos and papers (A-06). */
class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleService $vehicles,
        private readonly EstablishmentAccess $access,
        private readonly DocumentRequirements $requirements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $ids = $this->access->branchIds($actor);
        $vehicles = $actor->establishment->vehicles()->with('type.translations')
            ->when($ids !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('branch_id', $ids)->orWhereNull('branch_id')))
            ->orderByDesc('id')->get();

        return ApiResponse::success(VehicleResource::collection($vehicles), __('api.retrieved'));
    }

    public function store(VehicleRequest $request): JsonResponse
    {
        $vehicle = $this->vehicles->create($this->access->current($request), $request->validated());

        return ApiResponse::created($this->present($vehicle), __('provider.vehicle_added'));
    }

    public function show(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->vehicles->assertOwnVehicle($this->access->current($request), $vehicle);

        return ApiResponse::success($this->present($vehicle), __('api.retrieved'));
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle = $this->vehicles->update($this->access->current($request), $vehicle, $request->validated());

        return ApiResponse::success($this->present($vehicle), __('api.updated'));
    }

    public function deactivate(Request $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle = $this->vehicles->deactivate($this->access->current($request), $vehicle);

        return ApiResponse::success($this->present($vehicle), __('api.updated'));
    }

    /** The vehicle, the photos and papers it needs, what it has, and what is still missing. */
    private function present(Vehicle $vehicle): array
    {
        $vehicle->load('type.translations');
        $establishment = $vehicle->establishment;

        return [
            'vehicle' => new VehicleResource($vehicle),
            'required' => DocumentTypeResource::collection($this->requirements->for($establishment, DocumentSubject::Vehicle, $vehicle->vehicle_type_id)),
            'documents' => ProviderDocumentResource::collection($this->requirements->latest(DocumentSubject::Vehicle, $vehicle->id)->load('type')),
            'missing' => $this->requirements->missing($establishment, DocumentSubject::Vehicle, $vehicle->id, $vehicle->vehicle_type_id),
        ];
    }
}
