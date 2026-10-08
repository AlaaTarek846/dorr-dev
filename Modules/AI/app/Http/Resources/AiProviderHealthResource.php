<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiProviderHealthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'key' => $this->provider->key,
                'name' => $this->provider->name,
            ] : null),
            'check_type' => $this->check_type,
            'health_score' => (float) $this->health_score,
            'status' => $this->status,
            'details' => $this->details,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
