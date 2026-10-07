<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Http\Resources\AiFileCitationResource;

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
            'provider_key' => $this->provider_key,
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
            // Phase 11 (doc S14/S44): file-grounded citations for this
            // turn, only when the caller eager-loaded them
            // (AiConversationRepository::findForOwner() /
            // AiChatService's response-building calls - see each
            // ->fresh([...]) call site). Deliberately exposes only
            // real, already-computed metadata (file id/name + whichever
            // location fields AiRetrievalEngine::sourceReference()
            // actually produced for that chunk's content type) - never
            // invented page/sheet/slide/timestamp values, and never the
            // internal chunk_id/embedding ids (doc S37).
            'citations' => AiFileCitationResource::collection($this->whenLoaded('fileCitations')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
