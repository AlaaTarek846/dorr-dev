<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'usage_minutes' => $this->usage_minutes,
            'cooldown_minutes' => $this->cooldown_minutes,
            'image_daily_limit' => $this->image_daily_limit,
            'video_daily_limit' => $this->video_daily_limit,
            'video_max_seconds' => $this->video_max_seconds,
            'site_projects_limit' => $this->site_projects_limit,
            'site_daily_generations' => $this->site_daily_generations,
            'duration_days' => $this->duration_days,
            'price' => $this->price,
            'original_price' => $this->original_price,
            'discount_percent' => $this->discountPercent(),
            'currency' => $this->currency,
            'currency_id' => $this->currency_id,
            'badge' => $this->badge,
            'is_featured' => (bool) $this->is_featured,
            'features' => $this->features ?? [],
            'is_trial' => (bool) $this->is_trial,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
