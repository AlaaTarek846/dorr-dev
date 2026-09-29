<?php

namespace Modules\Wallet\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Modules\Wallet\Http\Resources\OnlineTransactionResource;
use Modules\Wallet\Models\PaymentTransaction;

/**
 * Read side of the admin "Online Transactions" screen (Jawad's
 * OnlinePaymentController, rebuilt): searchable list, per-currency totals, and
 * a detail view carrying the full gateway request/response history.
 */
class OnlineTransactionService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): JsonResponse
    {
        $query = $this->filtered($filters)
            ->with(['paymentMethod.translation', 'country', 'currency'])
            ->latest('id');

        return ApiResponse::paginated($query, OnlineTransactionResource::class, __('api.retrieved'));
    }

    /**
     * Money is never summed across currencies — one row per currency.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function summary(array $filters): array
    {
        $rows = $this->filtered($filters)
            ->selectRaw('currency_id, status, COUNT(*) as payments_count, SUM(requested_amount_minor) as total_minor')
            ->groupBy('currency_id', 'status')
            ->with('currency:id,code')
            ->get();

        return $rows->groupBy('currency_id')->map(function ($group) {
            $byStatus = $group->keyBy(fn ($row) => $row->status->value);

            return [
                'currency_code' => $group->first()->currency?->code,
                'by_status' => $byStatus->map(fn ($row) => [
                    'count' => (int) $row->payments_count,
                    'total_minor' => (int) $row->total_minor,
                ])->all(),
            ];
        })->values()->all();
    }

    public function show(int|string $id): PaymentTransaction
    {
        return PaymentTransaction::query()
            ->with([
                'paymentMethod.translation', 'country', 'currency', 'feeRule',
                'logs' => fn ($q) => $q->orderBy('id'),
                'walletTransactions' => fn ($q) => $q->orderBy('id'),
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        return PaymentTransaction::query()
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['payment_method_id'] ?? null, fn (Builder $q, $v) => $q->where('payment_method_id', $v))
            ->when($filters['country_id'] ?? null, fn (Builder $q, $v) => $q->where('country_id', $v))
            ->when($filters['owner_type'] ?? null, fn (Builder $q, $v) => $q->where('owner_type', $v))
            ->when($filters['owner_id'] ?? null, fn (Builder $q, $v) => $q->where('owner_id', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q->where(
                fn (Builder $w) => $w->where('uuid', 'like', "%{$v}%")->orWhere('gateway_reference', 'like', "%{$v}%"),
            ));
    }
}
