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
            // Root-cause fix: an admin screen showing "-" for every
            // subscriber whose name was never filled in (common for
            // phone/OTP-registered accounts with no profile step yet)
            // is useless for identifying who they actually are - the
            // phone number is the one identifier guaranteed to exist.
            // Exposed here once since every AI admin screen's "owner"
            // column already renders this shared shape.
            'phone' => $this->resource?->phone,
        ];
    }
}
