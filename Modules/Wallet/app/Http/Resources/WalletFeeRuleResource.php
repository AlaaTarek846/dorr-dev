<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletFeeRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'kind' => $this->isBonus() ? 'bonus' : ($this->isFee() ? 'fee' : 'none'),
            'percent' => $this->percent,
            'country_id' => $this->country_id,
            'payment_method_id' => $this->payment_method_id,
            'owner_type' => $this->owner_type,
            'min_amount_minor' => $this->min_amount_minor,
            'max_amount_minor' => $this->max_amount_minor,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'max_uses_per_owner' => $this->max_uses_per_owner,
            'budget_total_minor' => $this->budget_total_minor,
            'budget_used_minor' => $this->budget_used_minor,
            'budget_remaining_minor' => $this->budgetRemainingMinor(),
            'priority' => $this->priority,
            'status' => (bool) $this->status,
            'name' => $this->resource->translatedName(),
            'translations' => $this->whenLoaded('translations', fn () => $this->translations->map(fn ($item) => [
                'locale' => $item->locale,
                'name' => $item->name,
                'description' => $item->description,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
