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
use Modules\Rides\Enums\CaseKind;
use Modules\Rides\Enums\CaseStatus;
use Modules\Rides\Models\RideCase;
use Modules\Rides\Services\Cases\RideCases;

/**
 * Admin → Rides → Cases (R-133..R-140, D-229..D-238): the team's queue of complaints, financial disputes and
 * unpaid-ride reports — urgent first, then oldest — each with the evidence frozen when it was opened, the
 * conversation (and private notes), and the decision: an outcome, the reason the person is shown, and an
 * optional refund to the rider's wallet that only happens when the admin asks for it.
 */
class RideCaseController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ride-cases', [
            ['view', ['index', 'show', 'options']],
            ['update', ['status', 'message', 'file', 'decide', 'triage']],
        ]);
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'kinds' => array_map(fn (CaseKind $k) => ['code' => $k->value, 'label' => __('rides.cases.kinds.'.$k->value)], CaseKind::cases()),
            'statuses' => array_map(fn (CaseStatus $s) => ['code' => $s->value, 'label' => __('rides.cases.status.'.$s->value)], CaseStatus::cases()),
            'priorities' => ['urgent', 'high', 'normal'],
        ], __('api.retrieved'));
    }

    public function index(Request $request, RideCases $cases): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'kind' => ['nullable', 'string', 'max:16'],
            'priority' => ['nullable', 'in:urgent,high,normal'],
            'search' => ['nullable', 'string', 'max:40'],
            'open' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);
        $decided = array_map(fn (CaseStatus $s) => $s->value, array_filter(CaseStatus::cases(), fn (CaseStatus $s) => $s->isDecided()));
        $page = RideCase::query()->with('ride')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when(! empty($f['open']), fn ($q) => $q->whereNotIn('status', $decided))
            ->when($f['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'like', '%'.$v.'%')->orWhereHas('ride', fn ($r) => $r->where('number', 'like', '%'.$v.'%'))))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 else 2 end")->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::fromPaginator($page, $page->getCollection()->map(function (RideCase $c) use ($cases) {
            $row = $cases->present($c, 'admin');

            return array_intersect_key($row, array_flip(['id', 'uuid', 'number', 'kind', 'kind_label', 'category', 'category_label', 'status', 'status_label', 'priority', 'ride', 'disputed_minor', 'currency', 'reporter', 'assigned_to', 'created_at']));
        })->values(), __('api.retrieved'));
    }

    /** AI-12: (re)read the case with DORR AI now — e.g. when AI was off when it was opened. */
    public function triage(RideCase $case, RideCases $cases, \Modules\Rides\Services\Cases\CaseTriage $triage): JsonResponse
    {
        if ($triage->run($case) === null) {
            throw new \Modules\Rides\Exceptions\RidesException('ai_unavailable', 503);
        }

        return ApiResponse::success($cases->present($case->refresh(), 'admin'), __('api.updated'));
    }

    public function show(RideCase $case, RideCases $cases): JsonResponse
    {
        return ApiResponse::success($cases->present($case, 'admin'), __('api.retrieved'));
    }

    public function status(Request $request, RideCase $case, RideCases $cases, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:in_review,needs_info']]);
        $before = $case->status->value;
        $cases->setStatus($case, $request->user('admin_api'), CaseStatus::from($data['status']));
        $audit->record('ride_case.status', $case, ['status' => $before], ['status' => $data['status']]);

        return ApiResponse::success($cases->present($case->refresh(), 'admin'), __('api.updated'));
    }

    public function message(Request $request, RideCase $case, RideCases $cases): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'internal' => ['nullable', 'boolean']]);
        $cases->message($case, $request->user('admin_api'), $data['body'], (bool) ($data['internal'] ?? false));

        return ApiResponse::success($cases->present($case->refresh(), 'admin'), __('api.created'), 201);
    }

    public function file(Request $request, RideCase $case, RideCases $cases): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) config('rides.cases.max_file_kb', 5120)]]);
        $cases->attach($case, $request->user('admin_api'), $request->file('file'));

        return ApiResponse::success($cases->present($case->refresh(), 'admin'), __('api.created'), 201);
    }

    public function decide(Request $request, RideCase $case, RideCases $cases, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:confirmed,partially_confirmed,rejected'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'refund_minor' => ['nullable', 'integer', 'min:0'],
            'apply_refund' => ['nullable', 'boolean'],
        ]);
        $cases->decide($case, $request->user('admin_api'), $data);
        $audit->record('ride_case.decided', $case, null, ['outcome' => $data['outcome'], 'refund_minor' => (int) ($data['refund_minor'] ?? 0), 'refund_applied' => ! empty($data['apply_refund'])]);

        return ApiResponse::success($cases->present($case->refresh(), 'admin'), __('rides.cases.messages.decided'));
    }
}
