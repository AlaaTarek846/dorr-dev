<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiConversationContextResource extends JsonResource
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
            'context_type' => $this->context_type,
            'content' => $this->content,
            'included' => (bool) $this->included,
            'token_count' => $this->token_count,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
