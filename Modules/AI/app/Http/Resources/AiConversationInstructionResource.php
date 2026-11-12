<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One AI conversation instruction - the same keys as before; `is_active` is kept next to the catalog's `status`. */
class AiConversationInstructionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation' => $this->whenLoaded('conversation', fn () => $this->conversation ? [
                'id' => $this->conversation->id,
                'title' => $this->conversation->title,
            ] : null),
            'conversation_id' => $this->conversation_id,
            'source_type' => $this->source_type,
            'instruction' => $this->instruction,
            'priority' => $this->priority,
            'status' => (bool) $this->status,
            'is_active' => (bool) $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
