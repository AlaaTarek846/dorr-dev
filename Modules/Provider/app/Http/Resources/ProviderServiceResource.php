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
            'status' => $this->status?->value ?? $this->status,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name_ar' => $this->category->name_ar,
                'name_en' => $this->category->name_en,
                'department' => $this->category->department,
                'provider_type_label' => $this->category->provider_type_label,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
