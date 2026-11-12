<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI routing policy. `name` is in the request's language - the same keys as before; `is_active` is kept next
 * to the catalog's `status`. Only the admin panel gets `translations[]` (every language, for the language tabs).
 */
class AiRoutingPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scope_type' => $this->scope_type,
            'country_code' => $this->country_code,
            'service_key' => $this->service_key,
            'plan_id' => $this->plan_id,
            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan?->id,
                'name' => $this->plan?->name,
                'code' => $this->plan?->code,
            ]),
            'selection_strategy' => $this->selection_strategy,
            'fallback_enabled' => (bool) $this->fallback_enabled,
            'priority' => $this->priority,
            'rules_count' => $this->whenCounted('rules'),
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
