<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\AiSubscription;

class AiSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AiSubscription $this */
        return [
            'id' => $this->id,
            'owner' => $this->when($this->relationLoaded('owner'), fn () => new AiOwnerResource($this->owner)),
            'plan' => $this->when($this->relationLoaded('plan'), fn () => new AiPlanResource($this->plan)),
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'status' => $this->status,
            'auto_renew' => (bool) $this->auto_renew,
            'in_grace_period' => $this->isInGracePeriod(),
            'grace_ends_at' => $this->grace_ends_at?->toISOString(),
            'days_remaining' => $this->ends_at !== null
                ? max(0, (int) now()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay(), false))
                : null,
            'current_plan_price' => $this->current_plan_price,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
