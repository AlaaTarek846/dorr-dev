<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Stats\ServiceQuality;

/**
 * Admin → Rides → Quality & risk (Block 06): why captains are late and riders rate low (AI-19, with DORR
 * AI's reading on request), and the rides with fraud signs (AI-06).
 */
class RideInsightsController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('rides', [
            ['view', ['quality', 'explain', 'risky']],
        ]);
    }

    public function quality(Request $request, ServiceQuality $quality): JsonResponse
    {
        [$days, $country, $city] = $this->scope($request);

        return ApiResponse::success($quality->report($days, $country, $city), __('api.retrieved'));
    }

    public function explain(Request $request, ServiceQuality $quality): JsonResponse
    {
        [$days, $country, $city] = $this->scope($request);
        $reading = $quality->explain($quality->report($days, $country, $city));
        if ($reading === null) {
            throw new RidesException('ai_unavailable', 503);
        }

        return ApiResponse::success($reading, __('api.retrieved'));
    }

    /** Rides with fraud signs, the strongest first. */
    public function risky(Request $request): JsonResponse
    {
        $data = $request->validate([
            'min_score' => ['nullable', 'integer', 'between:1,100'],
            'days' => ['nullable', 'integer', 'between:1,365'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);
        $page = Ride::query()->where('risk_score', '>=', (int) ($data['min_score'] ?? 1))
            ->where('created_at', '>=', now()->subDays((int) ($data['days'] ?? 30)))
            ->with(['rider:id,name,phone', 'driver:id,name,phone', 'currency'])
            ->orderByDesc('risk_score')->latest('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::success([
            'items' => $page->getCollection()->map(fn (Ride $r) => [
                'id' => $r->id, 'number' => $r->number, 'status' => $r->status->value,
                'risk_score' => $r->risk_score,
                'flags' => collect((array) $r->risk_flags)->map(fn ($f) => ['code' => $f['code'] ?? null, 'label' => __('rides.fraud.'.($f['code'] ?? 'other')), 'detail' => $f['detail'] ?? []])->values(),
                'rider' => $r->rider ? $r->rider->only(['id', 'name', 'phone']) + ['is_verified' => \App\Support\AccountBadge::verified($r->rider)] : null,
                'driver' => $r->driver?->only(['id', 'name', 'phone']),
                'final_total_minor' => $r->final_total_minor,
                'currency' => $r->currency?->symbol,
                'completed_at' => $r->completed_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ], __('api.retrieved'));
    }

    /** @return array{0: int, 1: ?int, 2: ?int} */
    private function scope(Request $request): array
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'between:1,365'],
            'country_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
        ]);

        return [(int) ($data['days'] ?? 30), isset($data['country_id']) ? (int) $data['country_id'] : null, isset($data['city_id']) ? (int) $data['city_id'] : null];
    }
}
