<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI feature flag - the same keys as before. The description is in the request's language (X-Locale / locale
 * middleware); `is_enabled` (the old column) is kept next to the catalog's `status` - off = the kill switch is on.
 * Only the admin panel gets `translations[]` (every language, for the edit form's language tabs).
 */
class AiFeatureFlagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'target_type' => $this->target_type,
            'provider_id' => $this->provider_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'key' => $this->provider->key,
                'name' => $this->provider->name,
            ] : null),
            'model_key' => $this->model_key,
            'tool_key' => $this->tool_key,
            'country_code' => $this->country_code,
            'domain' => $this->domain,
            'environment' => $this->environment,
            'status' => (bool) $this->status,
            'is_enabled' => (bool) $this->status,
            'description' => $this->description,
            'translations' => $this->when(
                $request->is('api/admin/*') && $this->relationLoaded('translations'),
                fn () => $this->translations->map(fn ($item) => [
                    'locale' => $item->locale,
                    'description' => $item->description,
                ])->values(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
