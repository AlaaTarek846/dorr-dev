<?php

namespace Modules\Provider\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Provider\Enums\DocumentSubject;
use Modules\Provider\Exceptions\ProviderException;
use Modules\Provider\Http\Requests\App\DocumentUploadRequest;
use Modules\Provider\Http\Resources\App\DocumentTypeResource;
use Modules\Provider\Http\Resources\App\ProviderDocumentResource;
use Modules\Provider\Models\DocumentType;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Services\Documents\DocumentRequirements;
use Modules\Provider\Services\Documents\DocumentService;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\Provider\Services\Vehicles\VehicleService;

/**
 * Papers and photos of the establishment, a vehicle or a driver (A-06, A-07). The establishment's own papers are the
 * owner's; a vehicle's need fleet.vehicles; a driver uploads their own licence, or someone with fleet.drivers does.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentRequirements $requirements,
        private readonly EstablishmentAccess $access,
        private readonly VehicleService $vehicles,
    ) {}

    /** What the establishment itself, or one driver, still has to upload. */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $establishment = $actor->establishment;
        $driver = $request->query('driver_uuid') ? $this->driver($actor, (string) $request->query('driver_uuid')) : null;
        [$subject, $subjectId] = $driver ? [DocumentSubject::Driver, $driver->id] : [DocumentSubject::Establishment, $establishment->id];

        return ApiResponse::success([
            'required' => DocumentTypeResource::collection($this->requirements->for($establishment, $subject)),
            'documents' => ProviderDocumentResource::collection($this->requirements->latest($subject, $subjectId)->load('type')),
            'missing' => $this->requirements->missing($establishment, $subject, $subjectId),
        ], __('api.retrieved'));
    }

    public function store(DocumentUploadRequest $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $establishment = $actor->establishment;
        $subject = DocumentSubject::from($request->validated('subject'));
        $type = DocumentType::query()->findOrFail($request->validated('document_type_id'));

        $subjectId = match ($subject) {
            DocumentSubject::Establishment => $this->ownerOnly($actor, $establishment->id),
            DocumentSubject::Vehicle => $this->vehicle($actor, (string) $request->validated('subject_uuid'))->id,
            DocumentSubject::Driver => $this->driver($actor, (string) $request->validated('subject_uuid'))->id,
        };

        $document = $this->documents->upload(
            $establishment, $subject, $subjectId, $type,
            $request->file('front'), $request->file('back'),
            $request->safe()->only(['number', 'expires_on']),
        );

        return ApiResponse::created(new ProviderDocumentResource($document->load('type')), __('provider.document_uploaded'));
    }

    private function ownerOnly(Membership $actor, int $id): int
    {
        if (! $actor->is_owner) {
            throw new ProviderException('owner_only', 403);
        }

        return $id;
    }

    private function vehicle(Membership $actor, string $uuid): Vehicle
    {
        $vehicle = Vehicle::query()->where('uuid', $uuid)->firstOrFail();
        $this->vehicles->assertOwnVehicle($actor, $vehicle);
        $this->access->authorize($actor, 'fleet.vehicles', $vehicle->branch);

        return $vehicle;
    }

    /** A driver's own seat, or one managed by someone with fleet.drivers. */
    private function driver(Membership $actor, string $uuid): Membership
    {
        $driver = Membership::query()->where('uuid', $uuid)->where('establishment_id', $actor->establishment_id)->firstOrFail();
        if ($driver->id !== $actor->id) {
            $this->access->authorize($actor, 'fleet.drivers');
        }

        return $driver;
    }
}
