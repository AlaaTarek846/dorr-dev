<?php

namespace Modules\AI\Http\Resources\World;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\World\AiWorldLocation;

/** @mixin AiWorldLocation */
class AiWorldLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'kind' => $this->kind,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'address' => $this->formatted_address,
            'fidelity' => [
                'tier' => $this->fidelity_tier,
                'label' => __('ai.world_tier_'.$this->fidelity_tier),
                'description' => __('ai.world_tier_'.$this->fidelity_tier.'_desc'),
            ],
        ];
    }
}
