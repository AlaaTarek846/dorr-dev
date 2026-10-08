<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'attempt_number' => $this->attempt_number,
            'verifier_provider' => $this->whenLoaded('verifierProvider', fn () => $this->verifierProvider ? [
                'id' => $this->verifierProvider->id,
                'key' => $this->verifierProvider->key,
                'name' => $this->verifierProvider->name,
            ] : null),
            'draft_content' => $this->draft_content,
            'claims' => $this->claims,
            'issues' => $this->issues,
            'supported_claims_ratio' => $this->supported_claims_ratio,
            'completeness_score' => $this->completeness_score,
            'evidence_strength' => $this->evidence_strength,
            'confidence_score' => $this->confidence_score,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
