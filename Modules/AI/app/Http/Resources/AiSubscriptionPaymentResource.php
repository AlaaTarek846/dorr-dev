<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiSubscriptionPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => $this->when($this->relationLoaded('plan'), fn () => new AiPlanResource($this->plan)),
            'type' => $this->type,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
