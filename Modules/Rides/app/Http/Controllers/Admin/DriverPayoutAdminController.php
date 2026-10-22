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
use Modules\Rides\Models\DriverPayoutSetting;
use Modules\Rides\Services\Drivers\DriverPayouts;

/** Admin → captain payouts: international requests (D-224) and frozen accounts (D-299). */
class DriverPayoutAdminController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('driver-ranks', [['view', ['index']], ['update', ['international', 'unfreeze', 'freeze']]]);
    }

    public function index(): JsonResponse
    {
        $rows = DriverPayoutSetting::query()->where('international_status', 'pending')->orWhereNotNull('frozen_at')->orderByDesc('updated_at')->limit(200)->get();
        $drivers = Driver::query()->whereIn('id', $rows->pluck('driver_id'))->get(['id', 'name', 'phone'])->keyBy('id');

        return ApiResponse::success($rows->map(fn (DriverPayoutSetting $s) => [
            'driver' => $drivers[$s->driver_id] ?? null, 'international_status' => $s->international_status, 'international_reason' => $s->international_reason,
            'requested_at' => $s->international_requested_at?->toIso8601String(), 'frozen_at' => $s->frozen_at?->toIso8601String(), 'frozen_reason' => $s->frozen_reason,
        ])->values(), __('api.retrieved'));
    }

    public function international(Request $request, int $driver, DriverPayouts $payouts, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'answer' => ['nullable', 'string', 'max:500']]);
        $model = Driver::query()->findOrFail($driver);
        $payouts->answerInternational($model, (bool) $data['approve'], $data['answer'] ?? null);
        $audit->record('driver_payout.international', $model, null, $data);

        return ApiResponse::success($payouts->status($model), __('api.updated'));
    }

    public function freeze(Request $request, int $driver, DriverPayouts $payouts, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:160']]);
        $model = Driver::query()->findOrFail($driver);
        $payouts->freeze($model, $data['reason']);
        $audit->record('driver_payout.frozen', $model, null, $data);

        return ApiResponse::success($payouts->status($model), __('api.updated'));
    }

    public function unfreeze(int $driver, DriverPayouts $payouts, AuditLogger $audit): JsonResponse
    {
        $model = Driver::query()->findOrFail($driver);
        $payouts->unfreeze($model);
        $audit->record('driver_payout.unfrozen', $model);

        return ApiResponse::success($payouts->status($model), __('api.updated'));
    }
}
