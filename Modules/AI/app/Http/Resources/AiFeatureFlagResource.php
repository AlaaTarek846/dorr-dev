<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiFeatureFlagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'target_type' => $this->target_type,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'key' => $this->provider->key,
                'name' => $this->provider->name,
            ] : null),
            'model_key' => $this->model_key,
            'tool_key' => $this->tool_key,
            'country_code' => $this->country_code,
            'domain' => $this->domain,
            'environment' => $this->environment,
            'is_enabled' => (bool) $this->is_enabled,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
