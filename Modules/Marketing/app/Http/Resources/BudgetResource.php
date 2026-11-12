<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Services\Budgets\BudgetService;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $state = app(BudgetService::class)->state($this->resource);

        return [
            'id' => $this->uuid,
            'kind' => $this->kind,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'currency' => $this->currency,
            'cap' => $state['cap'],
            'used' => $state['used'],
            'estimated' => $state['estimated'],
            'remaining' => $state['remaining'],
            'pct' => $state['pct'],
            'level' => $state['level'],
            'alert_pct' => $this->alert_pct,
            'status' => $this->approval_status,
            'note' => $this->note,
            'active' => $this->approval_status === 'APPROVED' && $this->period_start->lte(now()) && $this->period_end->copy()->endOfDay()->gte(now()),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
        ];
    }
}
