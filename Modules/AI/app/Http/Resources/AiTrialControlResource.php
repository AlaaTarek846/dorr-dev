<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiTrialControlResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->when($this->relationLoaded('owner'), fn () => new AiOwnerResource($this->owner)),
            'trial_status' => $this->trial_status,
            'abuse_status' => $this->abuse_status,
            'abuse_reason' => $this->abuse_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
