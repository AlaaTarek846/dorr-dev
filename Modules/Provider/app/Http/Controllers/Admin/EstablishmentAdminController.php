<?php

namespace Modules\Provider\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Provider\Enums\DocumentSubject;
use Modules\Provider\Enums\EstablishmentStatus;
use Modules\Provider\Enums\MembershipStatus;
use Modules\Provider\Events\EstablishmentReviewed;
use Modules\Provider\Http\Resources\App\BranchResource;
use Modules\Provider\Http\Resources\App\EstablishmentResource;
use Modules\Provider\Http\Resources\App\MembershipResource;
use Modules\Provider\Http\Resources\App\ProviderDocumentResource;
use Modules\Provider\Http\Resources\App\VehicleResource;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\ProviderDocument;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Services\Documents\DocumentRequirements;
use Modules\Provider\Services\Documents\DocumentService;
use Modules\Provider\Services\Vehicles\VehicleService;

/**
 * Admin → Providers → Establishments (A-11): the review queue, one establishment with its branches, team, vehicles
 * and papers, and the decisions — approve, reject or suspend the establishment; approve or reject a vehicle or a
 * document (like the captains). Every decision is audited.
 */
class EstablishmentAdminController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly DocumentRequirements $requirements,
        private readonly DocumentService $documents,
        private readonly VehicleService $vehicles,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('provider-establishments', [
            ['view', ['index', 'show']],
            ['update', ['status', 'reviewVehicle', 'reviewDocument']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Establishment::query()->with(['owner:id,name,phone', 'country.translations'])
            ->withCount([
                'vehicles as vehicles_pending' => fn ($q) => $q->where('status', 'pending'),
                'documents as documents_pending' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('country_id'), fn ($q, $c) => $q->where('country_id', $c))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")
                ->orWhereHas('owner', fn ($q) => $q->where('phone', 'like', "%{$s}%"))))
            ->when($request->boolean('needs_review'), fn ($q) => $q->where(fn ($q) => $q->where('status', EstablishmentStatus::UnderReview->value)
                ->orWhereHas('vehicles', fn ($q) => $q->where('status', 'pending'))
                ->orWhereHas('documents', fn ($q) => $q->where('status', 'pending'))))
            ->orderByRaw("status = 'under_review' desc")->orderByDesc('submitted_at')->orderByDesc('id');

        $page = $query->paginate((int) $request->query('per_page', 20));

        return ApiResponse::fromPaginator($page, collect($page->items())->map(fn (Establishment $e) => [
            'uuid' => $e->uuid,
            'name' => $e->name,
            'type' => $e->type->value,
            'status' => $e->status->value,
            'country' => $e->country?->translatedName(),
            'owner' => ['name' => $e->owner?->name, 'phone' => $e->owner?->phone],
            'vehicles_pending' => $e->vehicles_pending,
            'documents_pending' => $e->documents_pending,
            'submitted_at' => $e->submitted_at?->toIso8601String(),
        ])->values(), __('api.retrieved'));
    }

    public function show(Establishment $establishment): JsonResponse
    {
        $establishment->load(['services', 'branches', 'owner:id,name,phone']);
        $members = $establishment->memberships()->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])->with('provider')->get();
        $vehicles = $establishment->vehicles()->with('type.translations')->orderByDesc('id')->get();

        return ApiResponse::success([
            'establishment' => new EstablishmentResource($establishment),
            'owner' => ['name' => $establishment->owner?->name, 'phone' => $establishment->owner?->phone],
            'branches' => BranchResource::collection($establishment->branches),
            'documents' => ProviderDocumentResource::collection($this->requirements->latest(DocumentSubject::Establishment, $establishment->id)->load('type.translations')),
            'members' => $members->map(fn ($m) => [
                ...(new MembershipResource($m))->resolve(),
                'documents' => ProviderDocumentResource::collection($this->requirements->latest(DocumentSubject::Driver, $m->id)->load('type.translations')),
            ])->values(),
            'vehicles' => $vehicles->map(fn (Vehicle $v) => [
                ...(new VehicleResource($v))->resolve(),
                'documents' => ProviderDocumentResource::collection($this->requirements->latest(DocumentSubject::Vehicle, $v->id)->load('type.translations')),
                'missing' => $this->requirements->missing($establishment, DocumentSubject::Vehicle, $v->id, $v->vehicle_type_id),
            ])->values(),
        ], __('api.retrieved'));
    }

    public function status(Request $request, Establishment $establishment, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([EstablishmentStatus::Approved->value, EstablishmentStatus::Rejected->value, EstablishmentStatus::Suspended->value])],
            'reason' => ['required_unless:status,approved', 'nullable', 'string', 'max:500'],
        ]);
        $before = $establishment->status->value;
        $establishment->update([
            'status' => $data['status'],
            'status_reason' => $data['status'] === EstablishmentStatus::Approved->value ? null : $data['reason'],
            'reviewed_by_admin_id' => $request->user('admin_api')->id,
            'reviewed_at' => now(),
        ]);
        $audit->record('provider_establishment.status', $establishment, ['status' => $before], $data);
        event(new EstablishmentReviewed($establishment));

        return ApiResponse::success(new EstablishmentResource($establishment), __('provider.establishment_'.$data['status']));
    }

    public function reviewVehicle(Request $request, Establishment $establishment, Vehicle $vehicle, AuditLogger $audit): JsonResponse
    {
        abort_unless($vehicle->establishment_id === $establishment->id, 404);
        $data = $this->decision($request);
        $adminId = $request->user('admin_api')->id;
        $data['decision'] === 'approve'
            ? $this->vehicles->approve($vehicle, $adminId)
            : $this->vehicles->reject($vehicle, $data['reason'], $adminId);
        $audit->record('provider_vehicle.reviewed', $vehicle, null, $data);

        return ApiResponse::success(new VehicleResource($vehicle->load('type.translations')), __('provider.vehicle_'.($data['decision'] === 'approve' ? 'approved' : 'rejected')));
    }

    public function reviewDocument(Request $request, Establishment $establishment, ProviderDocument $document, AuditLogger $audit): JsonResponse
    {
        abort_unless($document->establishment_id === $establishment->id, 404);
        $data = $this->decision($request);
        $adminId = $request->user('admin_api')->id;
        $data['decision'] === 'approve'
            ? $this->documents->approve($document, $adminId)
            : $this->documents->reject($document, $data['reason'], $adminId);
        $audit->record('provider_document.reviewed', $document, null, $data);

        return ApiResponse::success(new ProviderDocumentResource($document->load('type.translations')), __('provider.document_'.($data['decision'] === 'approve' ? 'approved' : 'rejected')));
    }

    /** @return array{decision: string, reason?: string|null} */
    private function decision(Request $request): array
    {
        return $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:500'],
        ]);
    }
}
