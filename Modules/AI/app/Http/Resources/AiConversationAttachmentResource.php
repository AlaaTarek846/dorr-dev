<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AiConversationAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The conversation can be gone (deleted / erased) while the attachment row is still listed.
            'conversation' => $this->whenLoaded('conversation', fn () => $this->conversation ? [
                'id' => $this->conversation->id,
                'title' => $this->conversation->title,
            ] : null),
            'message_id' => $this->message_id,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'is_image' => str_starts_with((string) $this->mime_type, 'image/'),
            'url' => $this->file_path ? Storage::disk('public')->url($this->file_path) : null,
            'created_at' => $this->created_at,
        ];
    }
}
