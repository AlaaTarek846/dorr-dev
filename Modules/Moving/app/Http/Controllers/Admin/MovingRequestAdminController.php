<?php

namespace Modules\Moving\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Modules\Moving\Http\Resources\MovingInventoryResource;
use Modules\Moving\Http\Resources\MovingRequestResource;
use Modules\Moving\Http\Resources\MovingTicketResource;
use Modules\Moving\Models\MovingChangeOrder;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Payment\MovingPayments;
use Modules\Moving\Services\Requests\MovingAfterService;

/**
 * Admin → Moving → Requests (B-17, REQ-084): every move live, by status, country, establishment or customer, and one
 * move's whole story — the steps with places and times, the offers, the changes, the inventory photos, the tickets,
 * the money held, paid and given back, and its invoice.
 */
class MovingRequestAdminController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('moving-requests', [['view', ['index', 'show', 'summary']]]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = MovingRequest::query()->with(['establishment', 'user:id,name,phone'])
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('country_id'), fn ($q, $c) => $q->where('country_id', $c))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('uuid', 'like', "{$s}%")
                ->orWhereHas('user', fn ($q) => $q->where('phone', 'like', "%{$s}%"))
                ->orWhereHas('establishment', fn ($q) => $q->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('id')->paginate((int) $request->query('per_page', 20));

        return ApiResponse::fromPaginator($rows, collect($rows->items())->map(fn (MovingRequest $r) => [
            'uuid' => $r->uuid,
            'status' => $r->status->value,
            'load_type' => $r->load_type?->value,
            'pickup_area' => $r->pickup_area,
            'dropoff_area' => $r->dropoff_area,
            'scheduled_at' => $r->scheduled_at?->toIso8601String(),
            'customer' => ['name' => $r->user?->name, 'phone' => $r->user?->phone],
            'establishment' => $r->establishment?->name,
            'total_minor' => $r->total_minor,
            'currency_code' => $r->currency_code,
            'payment_status' => $r->payment_status,
        ])->values(), __('api.retrieved'));
    }

    /** Counts by status and the money of the period (held now, paid to establishments, commission, refunds). */
    public function summary(Request $request): JsonResponse
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : now()->startOfMonth();
        $base = MovingRequest::query()->when($request->query('country_id'), fn ($q, $c) => $q->where('country_id', $c));

        return ApiResponse::success([
            'by_status' => (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'settled' => (clone $base)->whereNotNull('settled_at')->where('settled_at', '>=', $from)
                ->selectRaw('currency_code, COUNT(*) as jobs, SUM(total_minor) as total_minor, SUM(commission_minor) as commission_minor, SUM(provider_net_minor) as provider_net_minor, SUM(tax_minor) as tax_minor')
                ->groupBy('currency_code')->get(),
            'refunded' => (clone $base)->where('updated_at', '>=', $from)->whereNotNull('refunded_minor')
                ->selectRaw('currency_code, SUM(refunded_minor) as refunded_minor')->groupBy('currency_code')->get(),
            'from' => $from->toIso8601String(),
        ], __('api.retrieved'));
    }

    public function show(MovingRequest $move, MovingPayments $payments, MovingAfterService $after): JsonResponse
    {
        $move->load(['vehicleType.translations', 'establishment', 'vehicle', 'driver.provider', 'user:id,name,phone', 'acceptedOffer']);

        return ApiResponse::success([
            'request' => new MovingRequestResource($move),
            'customer' => ['name' => $move->user?->name, 'phone' => $move->user?->phone],
            'held_minor' => $payments->held($move),
            'money' => $move->only(['price_minor', 'tax_minor', 'total_minor', 'commission_minor', 'provider_net_minor', 'refunded_minor', 'payment_status']),
            'invoice' => $move->price_minor !== null ? $after->invoice($move, true) : null,
            'offers' => $move->offers()->with('establishment')->orderBy('price_minor')->get()->map(fn ($o) => [
                'uuid' => $o->uuid, 'establishment' => $o->establishment?->name, 'price_minor' => $o->price_minor, 'status' => $o->status->value,
            ])->values(),
            'changes' => MovingChangeOrder::query()->where('request_id', $move->id)->orderBy('id')->get(['uuid', 'kind', 'proposed_by', 'price_delta_minor', 'new_scheduled_at', 'reason', 'status', 'created_at']),
            'inventory' => MovingInventoryResource::collection($move->inventory),
            'tickets' => MovingTicketResource::collection($move->tickets()->get()),
            'events' => $move->events()->orderBy('id')->get(['action', 'from_status', 'to_status', 'actor_type', 'data', 'lat', 'lng', 'created_at']),
        ], __('api.retrieved'));
    }
}
