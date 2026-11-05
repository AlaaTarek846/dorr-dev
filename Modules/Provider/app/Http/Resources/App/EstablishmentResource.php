<?php

namespace Modules\Provider\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Provider\Models\Establishment;

/** @mixin Establishment */
class EstablishmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'name' => $this->name,
            'country_id' => $this->country_id,
            'commercial_register_no' => $this->commercial_register_no,
            'tax_number' => $this->tax_number,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'logo' => $this->getSingleMediaUrl('logo') ?: null,
            'services' => $this->whenLoaded('services', fn () => $this->services->map(fn ($s) => [
                'id' => $s->id,
                'module_name' => $s->module_name,
                'name' => $s->translatedName(),
                'image' => $s->getSingleMediaUrl('image') ?: null,
                'on' => (bool) $s->pivot->status,
            ])->values()),
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
        ];
    }
}
