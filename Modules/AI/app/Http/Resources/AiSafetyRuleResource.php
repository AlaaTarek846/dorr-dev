<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiSafetyRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'safety_policy_id' => $this->safety_policy_id,
            'policy' => $this->whenLoaded('policy', fn () => $this->policy ? [
                'id' => $this->policy->id,
                'name' => $this->policy->name,
            ] : null),
            'name' => $this->name,
            'condition' => $this->condition,
            'action' => $this->action,
            'priority' => $this->priority,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
