<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AiConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'provider_key' => $this->provider_key,
            'messages_count' => $this->whenCounted('messages'),
            'last_message_preview' => $this->when(
                $this->relationLoaded('latestMessage'),
                fn () => $this->previewOf($this->latestMessage?->content),
            ),
            'messages' => AiMessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    protected function previewOf(?string $content): ?string
    {
        if (! $content) {
            return null;
        }

        return Str::limit($content, 80);
    }
}
