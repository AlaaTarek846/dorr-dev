<?php

namespace Modules\AI\Http\Controllers\Ar;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Models\Ar\AiArExperience;
use Modules\AI\Models\Ar\AiArOrder;
use Modules\AI\Services\Ar\AiArOrderService;

/** Paying for an accepted design (customer) and getting paid for it (provider). Every lookup is scoped to the caller. */
class AiArOrderController extends Controller
{
    public function __construct(protected AiArOrderService $orders) {}

    // ---- customer ----

    public function acceptAndPay(Request $request, int $id)
    {
        $experience = AiArExperience::query()->where('owner_type', $request->user()->getMorphClass())->where('owner_id', $request->user()->getAuthIdentifier())->find($id)
            ?? throw new AiArException('not_found', 404);

        return ApiResponse::success($this->present($this->orders->acceptAndPay($request->user(), $experience)), 'OK', 201);
    }

    public function forExperience(Request $request, int $id)
    {
        $order = AiArOrder::query()->where('customer_type', $request->user()->getMorphClass())->where('customer_id', $request->user()->getAuthIdentifier())
            ->where('experience_id', $id)->latest('id')->first();

        return ApiResponse::success($order === null ? null : $this->present($order));
    }

    public function cancel(Request $request, int $id)
    {
        return ApiResponse::success($this->present($this->orders->cancel($this->mine($request, $id), 'customer')));
    }

    public function confirm(Request $request, int $id)
    {
        return ApiResponse::success($this->present($this->orders->confirm($this->mine($request, $id))));
    }

    public function dispute(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return ApiResponse::success($this->present($this->orders->dispute($this->mine($request, $id), $data['reason'])));
    }

    // ---- provider ----

    public function jobs(Request $request)
    {
        $rows = AiArOrder::query()->with('experience:id,title')->where('provider_id', $request->user()->getAuthIdentifier())
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->limit(100)->get();

        return ApiResponse::success($rows->map(fn ($o) => $this->present($o) + ['title' => $o->experience?->title])->all());
    }

    public function deliver(Request $request, int $id)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success($this->present($this->orders->deliver($this->job($request, $id), $data['note'] ?? null)));
    }

    public function decline(Request $request, int $id)
    {
        return ApiResponse::success($this->present($this->orders->cancel($this->job($request, $id), 'provider')));
    }

    protected function mine(Request $request, int $id): AiArOrder
    {
        return AiArOrder::query()->where('customer_type', $request->user()->getMorphClass())->where('customer_id', $request->user()->getAuthIdentifier())->find($id)
            ?? throw new AiArException('not_found', 404);
    }

    protected function job(Request $request, int $id): AiArOrder
    {
        return AiArOrder::query()->where('provider_id', $request->user()->getAuthIdentifier())->find($id) ?? throw new AiArException('not_found', 404);
    }

    public static function presentOrder(AiArOrder $o): array
    {
        return [
            'id' => $o->id, 'experience_id' => $o->experience_id, 'status' => $o->status, 'currency' => $o->currency,
            'amount_minor' => $o->amount_minor, 'commission_percent' => $o->commission_percent, 'commission_minor' => $o->commission_minor, 'provider_net_minor' => $o->provider_net_minor,
            'delivery_note' => $o->delivery_note, 'dispute_reason' => $o->dispute_reason, 'resolution_note' => $o->resolution_note,
            'paid_at' => $o->paid_at?->toIso8601String(), 'delivered_at' => $o->delivered_at?->toIso8601String(), 'release_at' => $o->release_at?->toIso8601String(),
            'released_at' => $o->released_at?->toIso8601String(), 'refunded_at' => $o->refunded_at?->toIso8601String(),
        ];
    }

    protected function present(AiArOrder $o): array
    {
        return self::presentOrder($o);
    }
}
