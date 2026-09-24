<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiLanguageVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'language' => $this->whenLoaded('language', fn () => $this->language ? [
                'id' => $this->language->id,
                'code' => $this->language->code,
                'name' => $this->language->name,
            ] : null),
            'language_id' => $this->language_id,
            'code' => $this->code,
            'name' => $this->name,
            'style' => $this->style,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
