<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiAuditEventResource extends JsonResource
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
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'type' => $this->actor_type,
                'id' => $this->actor_id,
                'name' => $this->actor->name ?? null,
            ] : null),
            'event_type' => $this->event_type,
            'severity' => $this->severity,
            'subject' => $this->subject_type ? [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
            ] : null,
            'trace_id' => $this->trace_id,
            'ip_address' => $this->ip_address,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
