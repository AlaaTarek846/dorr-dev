<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiKnowledgeSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file' => $this->whenLoaded('file', fn () => $this->file ? [
                'id' => $this->file->id,
                'file_name' => $this->file->file_name,
            ] : null),
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'name' => $this->name,
            'domain' => $this->domain,
            'country_code' => $this->country_code,
            'publisher' => $this->publisher,
            'authority' => $this->authority,
            'published_at' => $this->published_at?->toISOString(),
            'effective_at' => $this->effective_at?->toISOString(),
            'fetched_at' => $this->fetched_at?->toISOString(),
            'data_classification' => $this->data_classification,
            'access_scope' => $this->access_scope,
            'current_version' => $this->current_version,
            'change_detected' => (bool) $this->change_detected,
            'is_active' => (bool) $this->is_active,
            'approval_status' => $this->approval_status,
            'chunks_count' => $this->when($this->relationLoaded('chunks'), fn () => $this->chunks->count()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
