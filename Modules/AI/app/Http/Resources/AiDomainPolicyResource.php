<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI domain policy - the same keys as before. name / description are in the request's language (X-Locale /
 * locale middleware); `is_active` is kept next to the catalog's `status`. Only the admin panel gets `translations[]`
 * (every language, for the edit form's language tabs).
 */
class AiDomainPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_key' => $this->domain_key,
            'name' => $this->name,
            'description' => $this->description,
            'risk_level' => $this->risk_level,
            'requires_jurisdiction' => (bool) $this->requires_jurisdiction,
            'requires_triage' => (bool) $this->requires_triage,
            'sandbox_required' => (bool) $this->sandbox_required,
            'allowlist_enforced' => (bool) $this->allowlist_enforced,
            'system_prompt_addition' => $this->system_prompt_addition,
            'disclaimer_text' => $this->disclaimer_text,
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
