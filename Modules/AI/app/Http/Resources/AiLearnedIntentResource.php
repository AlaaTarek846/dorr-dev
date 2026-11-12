<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One learned phrase. `status` is the catalog's on/off switch (boolean, like every catalog screen) and `is_active`
 * stays next to it; the router's view of the row - active | pending | conflict - moved from `status` to `state`
 * (only the admin panel reads it).
 */
class AiLearnedIntentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
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
            'status' => (bool) $this->status,
            'is_active' => (bool) $this->status,
            'state' => $this->resource->state(),
            'source' => $this->source,
            'learned_with_model' => $this->learned_with_model,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
