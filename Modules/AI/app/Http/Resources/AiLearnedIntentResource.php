<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiLearnedIntentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = match (true) {
            $this->conflicts > 0 => 'conflict',
            $this->is_active => 'active',
            default => 'pending',
        };

        return [
            'id' => $this->id,
            'phrase' => $this->phrase,
            'match_mode' => $this->match_mode,
            'intent' => $this->intent,
            'file_format' => $this->file_format,
            'language' => $this->language,
            'confidence' => round((float) $this->confidence, 3),
            'confirmations' => $this->confirmations,
            'conflicts' => $this->conflicts,
            'hits' => $this->hits,
            'last_hit_at' => $this->last_hit_at?->toISOString(),
            'is_active' => (bool) $this->is_active,
            'status' => $status,
            'source' => $this->source,
            'learned_with_model' => $this->learned_with_model,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
