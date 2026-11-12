<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI gateway. `name` is in the request's language - the same keys as before; `is_active` is kept next to the
 * catalog's `status`. Only the admin panel gets `translations[]` (every language, for the language tabs).
 * `default_policy` also says whether that policy is switched on / in the trash (then the engine skips it).
 */
class AiGatewayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'environment' => $this->environment,
            'default_policy_id' => $this->default_policy_id,
            'default_policy' => $this->whenLoaded('defaultPolicy', fn () => $this->defaultPolicy ? [
                'id' => $this->defaultPolicy->id,
                'name' => $this->defaultPolicy->name,
                'status' => (bool) $this->defaultPolicy->status,
                'deleted' => $this->defaultPolicy->trashed(),
            ] : null),
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
