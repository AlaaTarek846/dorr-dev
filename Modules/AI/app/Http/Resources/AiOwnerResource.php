<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Small, shared "who owns this record" shape - used by every AI resource
 * that exposes a polymorphic owner (User or Provider).
 */
class AiOwnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource?->getMorphClass(),
            'id' => $this->resource?->getKey(),
            'name' => $this->resource?->name,
            'email' => $this->resource?->email,
        ];
    }
}
