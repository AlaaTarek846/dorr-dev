<?php

namespace App\Http\Resources\General;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceCategoryTreeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $children = ServiceCategoryTreeResource::collection($this->whenLoaded('children') ?? collect());

        return [
            'key' => (string) $this->id,
            'label' => $this->translatedName(),
            'selectable' => $children->collection->isEmpty(),
            'children' => $children->collection->isEmpty() ? [] : $children,
        ];
    }
}
