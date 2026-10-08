<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiKnowledgeChunkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_source' => $this->whenLoaded('knowledgeSource', fn () => $this->knowledgeSource ? [
                'id' => $this->knowledgeSource->id,
                'name' => $this->knowledgeSource->name,
            ] : null),
            'chunk_index' => $this->chunk_index,
            'content_ref' => $this->content_ref,
            'token_count' => $this->token_count,
            'searchable' => (bool) $this->searchable,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
