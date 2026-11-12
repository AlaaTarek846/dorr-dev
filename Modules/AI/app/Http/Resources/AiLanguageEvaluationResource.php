<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI language evaluation. `name` is in the request's language; `evaluation_status` is the result (pending /
 * running / passed / failed - the column was `status` before it became a catalog); `status` / `is_active` are the
 * on/off switch. Only the admin panel gets `translations[]`.
 */
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
                'status' => (bool) $this->variant->status,
                'deleted' => $this->variant->trashed(),
            ] : null),
            'variant_id' => $this->variant_id,
            'test_case_count' => $this->test_case_count,
            'pass_rate' => $this->pass_rate !== null ? (float) $this->pass_rate : null,
            'evaluation_status' => $this->evaluation_status,
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'notes' => $this->notes,
            'status' => (bool) $this->status,
            'is_active' => (bool) $this->status,
            'translations' => $this->when(
                $request->is('api/admin/*') && $this->relationLoaded('translations'),
                fn () => $this->translations->map(fn ($item) => [
                    'locale' => $item->locale,
                    'name' => $item->name,
                ])->values(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
