<?php

namespace Modules\Provider\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Provider\Models\Vehicle;

/** @mixin Vehicle */
class VehicleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'branch_id' => $this->branch_id,
            'vehicle_type' => $this->whenLoaded('type', fn () => new VehicleTypeResource($this->type)),
            'vehicle_type_id' => $this->vehicle_type_id,
            'plate_letters' => $this->plate_letters,
            'plate_number' => $this->plate_number,
            'make' => $this->make,
            'model' => $this->model,
            'color' => $this->color,
            'year' => $this->year,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
        ];
    }
}
