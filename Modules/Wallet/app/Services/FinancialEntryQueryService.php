<?php

namespace Modules\Wallet\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Modules\Wallet\Http\Resources\FinancialEntryResource;
use Modules\Wallet\Models\FinancialEntry;

/**
 * Read side of the platform's own income/expense ledger. Writing stays
 * exclusively in FinancialLedgerService::record().
 */
class FinancialEntryQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): JsonResponse
    {
        $query = $this->filtered($filters)
            ->with(['category.translation', 'currency:id,code', 'country:id,code'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        return ApiResponse::paginated($query, FinancialEntryResource::class, __('api.retrieved'));
    }

    /**
     * Per currency — amounts in different currencies are never added together.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function summary(array $filters): array
    {
        return $this->filtered($filters)
            ->selectRaw('currency_id, type, COUNT(*) as entries_count, SUM(amount_minor) as total_minor')
            ->groupBy('currency_id', 'type')
            ->with('currency:id,code')
            ->get()
            ->groupBy('currency_id')
            ->map(function ($rows) {
                $sum = fn (string $type) => (int) ($rows->first(fn ($r) => $r->type->value === $type)?->total_minor ?? 0);

                return [
                    'currency_code' => $rows->first()->currency?->code,
                    'income_minor' => $sum('income'),
                    'expense_minor' => $sum('expense'),
                    'net_minor' => $sum('income') - $sum('expense'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        return FinancialEntry::query()
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($filters['country_id'] ?? null, fn (Builder $q, $v) => $q->where('country_id', $v))
            ->when($filters['currency_id'] ?? null, fn (Builder $q, $v) => $q->where('currency_id', $v))
            ->when($filters['category'] ?? null, fn (Builder $q, $v) => $q->whereHas('category', fn (Builder $c) => $c->where('slug', $v)))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('entry_date', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('entry_date', '<=', $v));
    }
}
