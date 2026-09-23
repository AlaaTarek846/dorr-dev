<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'group_name' => $this->group_name,
            'service_category_id' => $this->service_category_id,
            'service_category' => $this->whenLoaded('serviceCategory', fn () => [
                'id' => $this->serviceCategory?->id,
                'module_name' => $this->serviceCategory?->module_name,
                'name' => $this->serviceCategory?->translation?->name
                    ?? $this->serviceCategory?->translations?->first()?->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
