<?php

namespace App\Http\Resources\General;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileAppColorDefaultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'is_active' => (bool) $this->is_active,
            'light_tokens' => $this->light_tokens ?? [],
            'dark_tokens' => $this->dark_tokens ?? [],
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
