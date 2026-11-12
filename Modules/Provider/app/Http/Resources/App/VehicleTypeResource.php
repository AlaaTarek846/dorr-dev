<?php

namespace Modules\Provider\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Provider\Models\VehicleType;

/** @mixin VehicleType */
class VehicleTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->translatedName(),
            'description' => $this->translated('description'),
            'image' => $this->getSingleMediaUrl('image') ?: null,
        ];
    }
}
