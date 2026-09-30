<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'usage_minutes' => $this->usage_minutes,
            'cooldown_minutes' => $this->cooldown_minutes,
            'duration_days' => $this->duration_days,
            'price' => $this->price,
            'original_price' => $this->original_price,
            'discount_percent' => $this->discountPercent(),
            'currency' => $this->currency,
            'badge' => $this->badge,
            'is_featured' => (bool) $this->is_featured,
            'features' => $this->features ?? [],
            'is_trial' => (bool) $this->is_trial,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
