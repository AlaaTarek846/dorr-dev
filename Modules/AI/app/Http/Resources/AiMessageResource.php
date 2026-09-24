<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'is_error' => (bool) $this->is_error,
            'model' => $this->model,
            'attachments' => AiConversationAttachmentResource::collection($this->whenLoaded('attachments')),
            'generated_file' => $this->when(
                isset($this->generated_file),
                fn () => $this->generated_file,
            ),
            'confidence_score' => $this->when(
                isset($this->confidence_score),
                fn () => $this->confidence_score,
            ),
            'verification_warnings' => $this->when(
                isset($this->verification_warnings),
                fn () => $this->verification_warnings,
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
