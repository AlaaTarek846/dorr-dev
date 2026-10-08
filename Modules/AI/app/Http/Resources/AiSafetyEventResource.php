<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiSafetyEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'type' => $this->owner_type,
                'id' => $this->owner_id,
                'name' => $this->owner->name ?? null,
            ] : null),
            'request_id' => $this->request_id,
            'safety_policy_id' => $this->safety_policy_id,
            'policy' => $this->whenLoaded('policy', fn () => $this->policy ? [
                'id' => $this->policy->id,
                'name' => $this->policy->name,
            ] : null),
            'safety_rule_id' => $this->safety_rule_id,
            'rule' => $this->whenLoaded('rule', fn () => $this->rule ? [
                'id' => $this->rule->id,
                'name' => $this->rule->name,
            ] : null),
            'action_taken' => $this->action_taken,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
