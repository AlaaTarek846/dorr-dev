<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiUsageSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->when($this->relationLoaded('owner'), fn () => new AiOwnerResource($this->owner)),
            'subscription' => $this->when(
                $this->relationLoaded('subscription') && $this->subscription,
                fn () => [
                    'id' => $this->subscription->id,
                    'plan' => $this->subscription->relationLoaded('plan')
                        ? new AiPlanResource($this->subscription->plan)
                        : null,
                ],
            ),
            'organization_id' => $this->organization_id,
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'duration_seconds' => $this->duration_seconds,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
