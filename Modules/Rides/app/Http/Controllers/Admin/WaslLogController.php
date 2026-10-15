<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Rides\Models\WaslLog;

/** Admin → Rides → Wasl logs (Jawad's screen): every call and answer, by type, result and subject. */
class WaslLogController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('wasl-logs', [['view', ['index', 'show']]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:registration,eligibility,trip,locations'],
            'success' => ['nullable', 'boolean'],
            'subject_type' => ['nullable', 'in:driver,ride'],
            'subject_id' => ['nullable', 'integer'],
            'result_code' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);
        $page = WaslLog::query()->with(['driver:id,name,phone', 'ride:id,number'])
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(array_key_exists('success', $filters) && $filters['success'] !== null, fn ($q) => $q->where('success', (bool) $filters['success']))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($filters['result_code'] ?? null, fn ($q, $v) => $q->where('result_code', $v))
            ->latest('id')->paginate((int) ($filters['per_page'] ?? 20));

        return ApiResponse::fromPaginator($page, $page->getCollection()->map(fn (WaslLog $l) => [
            'id' => $l->id,
            'type' => $l->type,
            'subject_type' => $l->subject_type,
            'subject_id' => $l->subject_id,
            'subject' => match ($l->subject_type) {
                'driver' => $l->driver ? trim(($l->driver->name ?? '').' '.$l->driver->phone) : null,
                'ride' => $l->ride?->number,
                default => null,
            },
            'status_code' => $l->status_code,
            'success' => $l->success,
            'result_code' => $l->result_code,
            'duration_ms' => $l->duration_ms,
            'created_at' => $l->created_at?->toIso8601String(),
        ])->values(), __('api.retrieved'));
    }

    public function show(WaslLog $waslLog): JsonResponse
    {
        return ApiResponse::success($waslLog->only(['id', 'type', 'subject_type', 'subject_id', 'request', 'response', 'status_code', 'success', 'result_code', 'duration_ms', 'created_at']), __('api.retrieved'));
    }
}
