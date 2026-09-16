<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'name_ar' => $this->parent->name_ar,
                'name_en' => $this->parent->name_en,
            ] : null),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'department' => $this->department,
            'base_model' => $this->base_model,
            'requires_provider' => (bool) $this->requires_provider,
            'provider_type_label' => $this->provider_type_label,
            'status' => (bool) $this->status,
            'sort_order' => $this->sort_order,
            'is_leaf' => $this->isLeaf(),
            'children' => ServiceCategoryResource::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
