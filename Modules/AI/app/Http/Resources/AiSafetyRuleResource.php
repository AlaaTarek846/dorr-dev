<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI safety rule - the same keys as before. The rule's and its policy's names are in the request's language
 * (X-Locale / locale middleware); the policy also says whether it is switched on / in the trash (then the guard
 * never applies the rule); `is_active` is kept next to the catalog's `status`. Only the admin panel gets
 * `translations[]` (every language, for the edit form's language tabs).
 */
class AiSafetyRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'safety_policy_id' => $this->safety_policy_id,
            'policy' => $this->whenLoaded('policy', fn () => $this->policy ? [
                'id' => $this->policy->id,
                'name' => $this->policy->name,
                'status' => (bool) $this->policy->status,
                'deleted' => $this->policy->trashed(),
            ] : null),
            'name' => $this->name,
            'condition' => $this->condition,
            'action' => $this->action,
            'priority' => $this->priority,
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
