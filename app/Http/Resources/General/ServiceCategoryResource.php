<?php

namespace App\Http\Resources\General;

use App\Http\Resources\Concerns\FormatsTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceCategoryResource extends JsonResource
{
    use FormatsTranslations;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge([
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? array_merge([
                'id' => $this->parent->id,
            ], (new self($this->parent))->translationFields()) : null),
            'module_name' => $this->module_name,
            'is_login_dashboard' => (bool) $this->is_login_dashboard,
            'is_auto_assign' => (bool) $this->is_auto_assign,
            'requires_provider' => (bool) $this->requires_provider,
            'status' => (bool) $this->status,
            'sort_order' => $this->sort_order,
            'image' => $this->getSingleMediaUrl('image') ?: null,
            'image_thumb' => $this->getSingleMediaThumbUrl('image') ?: null,
            'is_leaf' => $this->isLeaf(),
            'children' => ServiceCategoryResource::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ], $this->translationFields());
    }
}
