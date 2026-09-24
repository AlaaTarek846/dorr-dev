<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiSecurityEventResource extends JsonResource
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
            'event_type' => $this->event_type,
            'severity' => $this->severity,
            'correlation_id' => $this->correlation_id,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
