<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One AI provider data rule - the same keys as before; `is_active` is kept next to the catalog's `status`. */
class AiProviderDataRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'key' => $this->provider->key,
                'name' => $this->provider->name,
            ] : null),
            'sanitize_pii' => (bool) $this->sanitize_pii,
            'sanitize_secrets' => (bool) $this->sanitize_secrets,
            'transformation_rules' => $this->transformation_rules,
            'status' => (bool) $this->status,
            'is_active' => (bool) $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
