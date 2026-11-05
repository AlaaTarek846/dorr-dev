<?php

namespace Modules\Provider\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Provider\Models\Branch;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'is_main' => $this->is_main,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'radius_km' => $this->radius_km,
            'address' => $this->address,
            'status' => $this->status,
        ];
    }
}
