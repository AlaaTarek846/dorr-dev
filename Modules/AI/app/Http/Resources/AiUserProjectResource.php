<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The person's own project (spec 151) — as listed, or opened with its conversations and files. */
class AiUserProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'instructions' => $this->whenLoaded('instructions', fn () => $this->instructions->first()?->instruction),
            'conversations_count' => $this->whenCounted('projectConversations'),
            'files_count' => $this->whenCounted('projectFiles'),
            'conversations' => $this->whenLoaded('projectConversations', fn () => $this->projectConversations
                ->pluck('conversation')->filter()->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'updated_at' => $c->updated_at?->toISOString()])->values()),
            'files' => $this->whenLoaded('projectFiles', fn () => $this->projectFiles
                ->pluck('file')->filter()->map(fn ($f) => ['id' => $f->id, 'file_name' => $f->file_name, 'mime_type' => $f->mime_type, 'file_size' => $f->file_size])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
