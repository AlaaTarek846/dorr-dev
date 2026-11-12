<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI data policy - the same keys as before. name / description are in the request's language (X-Locale / locale
 * middleware); `is_active` is kept next to the catalog's `status`. Only the admin panel gets `translations[]` (every
 * language, for the edit form's language tabs).
 */
class AiDataPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'data_classification' => $this->data_classification,
            'retention_days' => $this->retention_days,
            'consent_required' => (bool) $this->consent_required,
            'minimization_enabled' => (bool) $this->minimization_enabled,
            'external_provider_allowed' => (bool) $this->external_provider_allowed,
            'description' => $this->description,
            'status' => (bool) $this->status,
            'is_active' => (bool) $this->status,
            'translations' => $this->when(
                $request->is('api/admin/*') && $this->relationLoaded('translations'),
                fn () => $this->translations->map(fn ($item) => [
                    'locale' => $item->locale,
                    'name' => $item->name,
                    'description' => $item->description,
                ])->values(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
