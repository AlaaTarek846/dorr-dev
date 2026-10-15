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
use Modules\Driver\Models\Driver;
use Modules\Rides\Models\RideSafetyIncident;
use Modules\Rides\Services\Safety\SafetyIncidentService;
use Modules\User\Models\User;

/**
 * Admin → Rides → Safety (R-110, D-141): every SOS and safety report (R-117, R-224, D-124, D-152..154)
 * and every unanswered "Are you OK?" (R-222, R-223), open ones first — who pressed it, where (a map
 * link), the ride, and the people to call; "on it" and "resolved" with a note (audited).
 */
class RideSafetyIncidentController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ride-safety', [['view', ['index']], ['update', ['acknowledge', 'resolve']]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,acknowledged,resolved'],
            'type' => ['nullable', 'string', 'max:16'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);
        $page = RideSafetyIncident::query()->with(['ride.rider', 'ride.driver', 'handledBy'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByRaw("case status when 'open' then 0 when 'acknowledged' then 1 else 2 end")
            ->latest('id')->paginate((int) ($filters['per_page'] ?? 20));

        return ApiResponse::fromPaginator($page, $page->getCollection()->map(fn (RideSafetyIncident $i) => $this->present($i))->values(), __('api.retrieved'));
    }

    public function acknowledge(Request $request, RideSafetyIncident $incident, SafetyIncidentService $safety, AuditLogger $audit): JsonResponse
    {
        $incident = $safety->acknowledge($incident, $request->user('admin_api'));
        $audit->record('ride_safety.acknowledged', $incident, null, ['status' => $incident->status]);

        return ApiResponse::success($this->present($incident->refresh()->load(['ride.rider', 'ride.driver', 'handledBy'])), __('api.updated'));
    }

    public function resolve(Request $request, RideSafetyIncident $incident, SafetyIncidentService $safety, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $incident = $safety->resolve($incident, $request->user('admin_api'), $data['note'] ?? null);
        $audit->record('ride_safety.resolved', $incident, null, ['status' => $incident->status, 'note' => $incident->resolution_note]);

        return ApiResponse::success($this->present($incident->refresh()->load(['ride.rider', 'ride.driver', 'handledBy'])), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(RideSafetyIncident $i): array
    {
        $reporter = $i->reporter_type === 'driver' ? Driver::query()->find($i->reporter_id) : User::query()->find($i->reporter_id);

        return [
            'id' => $i->id,
            'uuid' => $i->uuid,
            'type' => $i->type,
            'type_label' => __('rides.safety_types.'.$i->type),
            'status' => $i->status,
            'reporter_type' => $i->reporter_type,
            'reporter' => $reporter ? ['id' => $reporter->getKey(), 'name' => $reporter->name, 'phone' => $reporter->phone] : null,
            'ride' => $i->ride ? [
                'id' => $i->ride->id,
                'number' => $i->ride->number,
                'status' => $i->ride->status->value,
                'rider' => $i->ride->rider ? ['name' => $i->ride->rider->name, 'phone' => $i->ride->rider->phone] : null,
                'driver' => $i->ride->driver ? ['id' => $i->ride->driver->id, 'name' => $i->ride->driver->name, 'phone' => $i->ride->driver->phone] : null,
            ] : null,
            'ride_status' => $i->ride_status,
            'lat' => $i->lat,
            'lng' => $i->lng,
            'accuracy_m' => $i->accuracy_m,
            'map_url' => $i->lat === null ? null : 'https://www.google.com/maps/search/?api=1&query='.$i->lat.','.$i->lng,
            'note' => $i->note,
            'handled_by' => $i->handledBy?->name,
            'acknowledged_at' => $i->acknowledged_at?->toIso8601String(),
            'resolved_at' => $i->resolved_at?->toIso8601String(),
            'resolution_note' => $i->resolution_note,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
