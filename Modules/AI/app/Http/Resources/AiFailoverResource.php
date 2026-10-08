<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiFailoverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'primary_provider' => $this->whenLoaded('primaryProvider', fn () => $this->primaryProvider ? [
                'id' => $this->primaryProvider->id,
                'key' => $this->primaryProvider->key,
                'name' => $this->primaryProvider->name,
            ] : null),
            'fallback_provider' => $this->whenLoaded('fallbackProvider', fn () => $this->fallbackProvider ? [
                'id' => $this->fallbackProvider->id,
                'key' => $this->fallbackProvider->key,
                'name' => $this->fallbackProvider->name,
            ] : null),
            'request_id' => $this->request_id,
            'trigger_type' => $this->trigger_type,
            'attempt_number' => $this->attempt_number,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
