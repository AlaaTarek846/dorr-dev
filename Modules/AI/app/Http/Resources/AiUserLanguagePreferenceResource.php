<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiUserLanguagePreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'type' => $this->owner_type,
                'id' => $this->owner_id,
                'name' => $this->owner->name ?? null,
            ] : null),
            'language' => $this->whenLoaded('language', fn () => $this->language ? [
                'id' => $this->language->id,
                'code' => $this->language->code,
                'name' => $this->language->name,
            ] : null),
            'variant' => $this->whenLoaded('variant', fn () => $this->variant ? [
                'id' => $this->variant->id,
                'code' => $this->variant->code,
                'name' => $this->variant->name,
            ] : null),
            'auto_detect' => (bool) $this->auto_detect,
            'response_language_mode' => $this->response_language_mode,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
