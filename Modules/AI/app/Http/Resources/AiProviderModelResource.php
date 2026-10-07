<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\AI\Models\AiProviderModel
 */
class AiProviderModelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'model_key' => $this->model_key,
            'display_name' => $this->display_name ?: $this->model_key,
            'capabilities' => $this->capabilities ?? [],
            'temperature' => $this->temperature !== null ? (float) $this->temperature : null,
            // NULL means "not computed yet" (see the migration's
            // docblock) - defaults to true so a row nobody has recalculated
            // classification for yet keeps showing its temperature control
            // exactly as it always has, rather than losing it the moment
            // this column exists.
            'temperature_supported' => $this->temperature_supported ?? true,
            'max_tokens' => $this->max_tokens,
            'max_output_tokens' => $this->max_output_tokens,
            'context_window' => $this->context_window,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,

            // Dynamic Model Registry fields (see the
            // add_registry_fields_to_ai_provider_models_table migration).
            'category' => $this->category,
            'needs_review' => (bool) $this->needs_review,
            'status' => $this->status,
            'model_family' => $this->model_family,
            'canonical_model_id' => $this->canonical_model_id,
            'is_alias' => (bool) $this->is_alias,
            'is_snapshot' => (bool) $this->is_snapshot,
            'release_date' => $this->release_date?->toDateString(),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'deprecated_at' => $this->deprecated_at?->toISOString(),
        ];
    }
}
