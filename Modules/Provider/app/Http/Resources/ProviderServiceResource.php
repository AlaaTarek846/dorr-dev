<?php

namespace Modules\Provider\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_category_id' => $this->service_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->translatedName(),
                'requires_provider' => (bool) $this->category->requires_provider,
                'image' => $this->category->getSingleMediaUrl('image') ?: null,
                'translations' => $this->category->relationLoaded('translations')
                    ? $this->category->translations->map(fn ($item) => [
                        'locale' => $item->locale,
                        'name' => $item->name,
                    ])->values()
                    : [],
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
