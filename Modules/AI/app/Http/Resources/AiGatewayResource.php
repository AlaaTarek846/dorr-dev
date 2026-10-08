<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiGatewayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'environment' => $this->environment,
            'default_policy_id' => $this->default_policy_id,
            'default_policy' => $this->whenLoaded('defaultPolicy', fn () => $this->defaultPolicy ? [
                'id' => $this->defaultPolicy->id,
                'name' => $this->defaultPolicy->name,
            ] : null),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
