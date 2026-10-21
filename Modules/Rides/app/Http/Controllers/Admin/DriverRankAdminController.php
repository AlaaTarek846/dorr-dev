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
use Modules\Rides\Http\Controllers\Driver\DriverRankController;
use Modules\Rides\Models\DriverPerformanceNote;
use Modules\Rides\Models\DriverRank;
use Modules\Rides\Services\Drivers\DriverRanks;

/**
 * Admin → Rides → Captain ranks (D-239..D-259): the ranks and their thresholds, one captain's numbers and record
 * (write a note / warning / praise), and the appeals waiting for an answer.
 */
class DriverRankAdminController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('driver-ranks', [
            ['view', ['index', 'driver', 'appeals']],
            ['create', ['store', 'note']],
            ['update', ['update', 'answer']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(DriverRank::query()->orderBy('sort_order')->get()->map(fn (DriverRank $r) => $r->toArray())->values(), __('api.retrieved'));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $rank = DriverRank::query()->create($this->validated($request));
        $audit->record('driver_rank.created', $rank, null, $rank->toArray());

        return ApiResponse::success($rank->refresh()->toArray(), __('api.created'), 201);
    }

    public function update(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $rank = DriverRank::query()->findOrFail($id);
        $before = $rank->toArray();
        $rank->fill($this->validated($request, $rank->id))->save();
        $audit->record('driver_rank.updated', $rank, $before, $rank->toArray());

        return ApiResponse::success($rank->refresh()->toArray(), __('api.updated'));
    }

    public function destroy(int $id, AuditLogger $audit): JsonResponse
    {
        $rank = DriverRank::query()->findOrFail($id);
        $rank->delete();
        $audit->record('driver_rank.deleted', $rank);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** One captain by phone: rank, numbers, record. */
    public function driver(Request $request, DriverRanks $ranks): JsonResponse
    {
        $phone = trim((string) $request->query('phone', ''));
        $driver = $phone === '' ? null : Driver::query()->where('phone', 'like', '%'.ltrim($phone, '+0').'%')->first();
        if ($driver === null) {
            return ApiResponse::success(null, __('api.retrieved'));
        }
        $state = $ranks->evaluate($driver);

        return ApiResponse::success([
            'driver' => ['id' => $driver->id, 'name' => $driver->name, 'phone' => $driver->phone],
            'probation' => $ranks->probation($driver),
            'rank' => $state->rank?->localized('name'), 'metrics' => $state->metrics,
            'falling_due_at' => $state->downgrade_due_at?->toIso8601String(),
            'notes' => DriverPerformanceNote::query()->where('driver_id', $driver->id)->orderByDesc('id')->limit(100)->get()->map(fn ($n) => DriverRankController::note($n))->values(),
        ], __('api.retrieved'));
    }

    public function note(Request $request, int $driver, DriverRanks $ranks, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:note,warning,praise'], 'title' => ['required', 'string', 'max:160'], 'body' => ['nullable', 'string', 'max:2000'],
        ]);
        $model = Driver::query()->findOrFail($driver);
        $note = $ranks->note($model, $data['kind'], $data['title'], $data['body'] ?? null, (int) $request->user()->getKey());
        $audit->record('driver_performance.noted', $model, null, $data);

        return ApiResponse::success(DriverRankController::note($note), __('api.created'), 201);
    }

    public function appeals(): JsonResponse
    {
        return ApiResponse::success(DriverPerformanceNote::query()->where('appeal_status', 'pending')->orderBy('appealed_at')->limit(200)->get()
            ->map(fn (DriverPerformanceNote $n) => DriverRankController::note($n) + ['driver' => Driver::query()->whereKey($n->driver_id)->first(['id', 'name', 'phone'])])->values(), __('api.retrieved'));
    }

    public function answer(Request $request, int $note, DriverRanks $ranks, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'answer' => ['required', 'string', 'max:1000']]);
        $row = DriverPerformanceNote::query()->findOrFail($note);
        $row = $ranks->answer($row, (bool) $data['accept'], $data['answer']);
        $audit->record('driver_performance.appeal_answered', $row, null, $data);

        return ApiResponse::success(DriverRankController::note($row), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:24', 'alpha_dash', 'unique:driver_ranks,slug'.($id ? ','.$id : '')],
            'name' => ['required', 'array'], 'name.ar' => ['required', 'string', 'max:80'], 'name.en' => ['required', 'string', 'max:80'],
            'benefits' => ['nullable', 'array'], 'benefits.ar' => ['nullable', 'array'], 'benefits.ar.*' => ['string', 'max:160'],
            'benefits.en' => ['nullable', 'array'], 'benefits.en.*' => ['string', 'max:160'],
            'color' => ['nullable', 'string', 'max:9'], 'sort_order' => ['required', 'integer', 'min:0'], 'probation' => ['required', 'boolean'],
            'min_trips' => ['required', 'integer', 'min:0'], 'min_rating' => ['required', 'integer', 'between:0,500'],
            'min_cleanliness' => ['required', 'integer', 'between:0,500'], 'min_behavior' => ['required', 'integer', 'between:0,500'],
            'min_commitment_bp' => ['required', 'integer', 'between:0,10000'], 'min_completion_bp' => ['required', 'integer', 'between:0,10000'],
            'max_cancel_bp' => ['required', 'integer', 'between:0,10000'], 'max_warnings' => ['required', 'integer', 'between:0,99'],
            'status' => ['required', 'boolean'],
        ]);

        return $data;
    }
}
