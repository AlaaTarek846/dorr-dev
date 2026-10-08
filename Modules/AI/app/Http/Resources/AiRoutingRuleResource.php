<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiRoutingRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'routing_policy_id' => $this->routing_policy_id,
            'policy' => $this->whenLoaded('policy', fn () => $this->policy ? [
                'id' => $this->policy->id,
                'name' => $this->policy->name,
            ] : null),
            'intent_id' => $this->intent_id,
            'intent' => $this->whenLoaded('intent', fn () => $this->intent ? [
                'id' => $this->intent->id,
                'key' => $this->intent->key,
                'name' => $this->intent->name,
            ] : null),
            'provider_id' => $this->provider_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'key' => $this->provider->key ?? null,
                'name' => $this->provider->name,
            ] : null),
            'model_key' => $this->model_key,
            'priority' => $this->priority,
            'selection_config' => $this->selection_config,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
