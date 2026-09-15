<?php

namespace App\Http\Resources\Concerns;

trait FormatsTranslations
{
    /**
     * @return array<string, mixed>
     */
    protected function translationFields(): array
    {
        return [
            'name' => $this->resource->translatedName(),
            'translations' => $this->whenLoaded('translations', fn () => $this->translations->map(fn ($item) => [
                'locale' => $item->locale,
                'name' => $item->name,
            ])->values()),
        ];
    }
}
