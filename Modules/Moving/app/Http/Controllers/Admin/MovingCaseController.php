<?php

namespace Modules\Moving\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Moving\Http\Resources\MovingInventoryResource;
use Modules\Moving\Http\Resources\MovingRequestResource;
use Modules\Moving\Models\MovingCase;
use Modules\Moving\Services\Payment\MovingSettlement;
use Modules\Moving\Services\Requests\MovingCancellationService;

/**
 * Admin → Moving → Cases (B-14, B-17): customers' reports after delivery and the exceptions either side asked for.
 * A person decides each one with the request's whole story in front of them — the steps, the inventory photos before
 * and after, the evidence (REQ-006, REQ-059). Every decision is audited.
 */
class MovingCaseController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('moving-cases', [
            ['view', ['index', 'show']],
            ['update', ['decide']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = MovingCase::query()->with('request:id,uuid,status,total_minor,currency_code')
            ->when($request->query('status', 'open'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->orderByDesc('id')->paginate(20);

        return ApiResponse::fromPaginator($rows, collect($rows->items())->map(fn (MovingCase $c) => $this->present($c))->values(), __('api.retrieved'));
    }

    public function show(MovingCase $case): JsonResponse
    {
        $move = $case->request->load(['vehicleType.translations', 'establishment', 'vehicle', 'driver.provider']);

        return ApiResponse::success([
            'case' => $this->present($case),
            'request' => new MovingRequestResource($move),
            'inventory' => MovingInventoryResource::collection($move->inventory),
            'events' => $move->events()->orderBy('id')->get(['action', 'from_status', 'to_status', 'actor_type', 'data', 'lat', 'lng', 'created_at']),
        ], __('api.retrieved'));
    }

    public function decide(Request $request, MovingCase $case, MovingSettlement $settlement, MovingCancellationService $cancellations, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'award_minor' => [$case->kind === MovingCase::REPORT ? 'required' : 'prohibited', 'integer', 'min:0'],
            'outcome' => [$case->kind === MovingCase::EXCEPTION ? 'required' : 'prohibited', 'in:refund_all,fee,continue'],
            'note' => ['required', 'string', 'max:1000'],
        ]);
        $adminId = $request->user('admin_api')->id;
        $case = $case->kind === MovingCase::REPORT
            ? $settlement->decideReport($case, (int) $data['award_minor'], $data['note'], $adminId)
            : $cancellations->decideException($case, $data['outcome'], $data['note'], $adminId);
        $audit->record('moving_case.decided', $case, null, $data);

        return ApiResponse::success($this->present($case->fresh()), __('moving.case_decided'));
    }

    /** @return array<string, mixed> */
    private function present(MovingCase $case): array
    {
        return [
            'uuid' => $case->uuid,
            'kind' => $case->kind,
            'opened_by' => $case->opened_by,
            'reason_code' => $case->reason_code,
            'details' => $case->details,
            'claim_minor' => $case->claim_minor,
            'held_minor' => $case->held_minor,
            'status' => $case->status,
            'decision' => $case->decision,
            'award_minor' => $case->award_minor,
            'decision_note' => $case->decision_note,
            'evidence' => $case->getMedia('evidence')->map(fn ($m) => $m->getUrl())->values(),
            'request' => $case->relationLoaded('request') && $case->request ? [
                'uuid' => $case->request->uuid, 'status' => $case->request->status->value,
                'total_minor' => $case->request->total_minor, 'currency_code' => $case->request->currency_code,
            ] : null,
            'created_at' => $case->created_at?->toIso8601String(),
        ];
    }
}
