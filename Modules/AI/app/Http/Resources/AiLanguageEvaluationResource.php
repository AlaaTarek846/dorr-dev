<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiLanguageEvaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Root-cause fix (languages consolidation): "language" is now
            // App\Models\Language, which has no plain "name" column -
            // display names are per-locale, via translatedName().
            'language' => $this->whenLoaded('language', fn () => $this->language ? [
                'id' => $this->language->id,
                'code' => $this->language->code,
                'name' => $this->language->translatedName(),
            ] : null),
            'language_id' => $this->language_id,
            'variant' => $this->whenLoaded('variant', fn () => $this->variant ? [
                'id' => $this->variant->id,
                'code' => $this->variant->code,
                'name' => $this->variant->name,
            ] : null),
            'variant_id' => $this->variant_id,
            'test_case_count' => $this->test_case_count,
            'pass_rate' => $this->pass_rate !== null ? (float) $this->pass_rate : null,
            'status' => $this->status,
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
