<?php

namespace Modules\Moving\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Moving\Models\MovingInventoryItem;

/** A numbered piece with its photo before loading and after delivery (REQ-029, REQ-031). @mixin MovingInventoryItem */
class MovingInventoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'number' => $this->number,
            'label' => $this->label,
            'trip_no' => $this->trip_no,
            'condition_note' => $this->condition_note,
            'delivered_note' => $this->delivered_note,
            'before' => $this->getSingleMediaUrl('before') ?: null,
            'after' => $this->getSingleMediaUrl('after') ?: null,
        ];
    }
}
