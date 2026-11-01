<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Modules\Freelance\Services\Reports\FreelanceMoneyReport;

/** The project money for staff (FL-512): the summary per country for a period, and the reconciliation. */
class FreelanceReportController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FreelanceMoneyReport $report) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-reports', [
            ['view', ['summary', 'reconcile', 'cancelReasons']],
        ]);
    }

    /** GET freelance-reports/summary[?country_id=&from=Y-m-d&to=Y-m-d] — the current month by default. */
    public function summary(Request $request): JsonResponse
    {
        $f = $request->validate([
            'country_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = isset($f['from']) ? Carbon::parse($f['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay();

        return ApiResponse::success([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'countries' => $this->report->summary(isset($f['country_id']) ? (int) $f['country_id'] : null, $from, $to),
        ], __('api.retrieved'));
    }

    /** GET freelance-reports/cancel-reasons[?from=&to=&responsibility=client|freelancer|shared|none|review] - why work was called off (FL-607); information only. */
    public function cancelReasons(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'responsibility' => ['nullable', \Illuminate\Validation\Rule::in(\Modules\Freelance\Enums\FreelanceCancelReason::responsibilities())],
        ]);
        $from = isset($f['from']) ? Carbon::parse($f['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay();

        return ApiResponse::success(app(\Modules\Freelance\Services\Projects\CancelReasons::class)->summary($from, $to, $f['responsibility'] ?? null), __('api.retrieved'));
    }

    /** GET freelance-reports/reconcile[?country_id=] — every difference found; nothing is changed. */
    public function reconcile(Request $request): JsonResponse
    {
        $f = $request->validate(['country_id' => ['nullable', 'integer']]);
        $issues = $this->report->reconcile(isset($f['country_id']) ? (int) $f['country_id'] : null);

        return ApiResponse::success(['checked_at' => now()->toIso8601String(), 'count' => count($issues), 'issues' => $issues], __('api.retrieved'));
    }
}
